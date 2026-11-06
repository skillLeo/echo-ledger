<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Plans, roles and privacy (since v1.5, docs/API.md "Workspaces, roles and privacy" and "Plans and features"): plans
 * gain a price (0 until billing comes), retention days, seats, AI credits per month and their features; the Personal
 * plan every existing workspace is on has no limits and every feature, and a Free plan is added for new sign-ups.
 * Roles admin and viewer need no schema change (`workspace_user.role` is a string). Screens record what their two
 * encrypted files take, for the plan's storage. Content keeps the `user_id` of the person who made it; a row without
 * one (none is expected) goes to the first owner of its workspace. What one person filed or nested in another person's
 * project moves to a project of its own maker (untangle()).
 *
 * Rollback moves workspaces on Free back to Personal, deletes Free, gives Personal its B20 limits again where they are
 * empty and drops the new columns. Nothing of the library changes: the projects untangle() made stay, with what moved
 * into them (B20 shows them to the whole workspace, next to the projects they were made from).
 */
return new class extends Migration
{
    /** The B20 limits of Personal (Plan::PERSONAL_LIMITS before v1.5), for the rollback. */
    private const B20_PERSONAL = ['monthly_minutes' => 30000, 'storage_bytes' => 536870912000, 'max_devices' => 25];

    /** Content tables whose rows belong to the person in `user_id`. */
    private const OWNED = ['capture_sessions', 'clients', 'client_files', 'request_links'];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->decimal('price_usd', 10, 2)->default(0);
            $table->unsignedSmallInteger('retention_days')->nullable(); // null = no limit (the console's retention)
            $table->unsignedInteger('seats')->nullable();
            $table->decimal('ai_credits_usd', 10, 2)->nullable();
            // {"live_translate": true, "screen_moments": true, "ai_notes": true, "connector": true}; null = every feature
            $table->json('features')->nullable();
        });
        Schema::table('screens', function (Blueprint $table): void {
            // The encrypted picture and thumbnail on disk, which the plan's storage counts; null for a screen stored
            // before (its picture's size is counted then).
            $table->unsignedBigInteger('stored_bytes')->nullable();
        });

        $all = json_encode(['live_translate' => true, 'screen_moments' => true, 'ai_notes' => true, 'connector' => true]);
        $now = now();
        // Personal keeps a limit the super admin changed; the B20 defaults become no limit.
        $personal = DB::table('plans')->where('key', 'personal')->first();
        if ($personal !== null) {
            $update = ['features' => $all, 'updated_at' => $now];
            foreach (self::B20_PERSONAL as $column => $value) {
                if ((int) $personal->{$column} === $value) {
                    $update[$column] = null;
                }
            }
            DB::table('plans')->where('id', $personal->id)->update($update);
        } else {
            DB::table('plans')->insert(['key' => 'personal', 'name' => 'Personal', 'features' => $all, 'created_at' => $now, 'updated_at' => $now]);
        }
        if (! DB::table('plans')->where('key', 'free')->exists()) {
            // Placeholders until the prices are decided.
            DB::table('plans')->insert([
                'key' => 'free', 'name' => 'Free', 'price_usd' => 0, 'monthly_minutes' => 300, 'storage_bytes' => 5 * 1024 ** 3,
                'max_devices' => 3, 'retention_days' => 30, 'seats' => 1, 'ai_credits_usd' => 1,
                'features' => json_encode(['live_translate' => false, 'screen_moments' => true, 'ai_notes' => false, 'connector' => true]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Each person's filing rules are their own, as their projects are: the same title or app may file one person's
        // recordings into their project and another person's into theirs.
        Schema::table('filing_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('workspace_id');
            // MariaDB may use the old unique index for the workspace_id foreign key: give that key its own index first.
            $table->index('workspace_id', 'filing_rules_workspace_id_index');
        });
        DB::table('filing_rules')->whereNull('user_id')->orderBy('id')->chunkById(500, function ($rules): void {
            $owners = DB::table('clients')->whereIn('id', $rules->pluck('client_id')->unique()->all())->pluck('user_id', 'id');
            foreach ($rules as $rule) {
                DB::table('filing_rules')->where('id', $rule->id)->update(['user_id' => $owners[$rule->client_id] ?? null]);
            }
        });
        Schema::table('filing_rules', function (Blueprint $table): void {
            $table->dropUnique(['workspace_id', 'kind', 'match_value']);
            $table->unique(['workspace_id', 'user_id', 'kind', 'match_value'], 'filing_rules_person_rule_unique');
        });

        foreach (self::OWNED as $table) {
            if (! Schema::hasColumn($table, 'user_id') || ! DB::table($table)->whereNull('user_id')->exists()) {
                continue;
            }
            DB::table($table)->whereNull('user_id')->orderBy('workspace_id')->select('workspace_id')->distinct()->pluck('workspace_id')
                ->each(function ($workspaceId) use ($table): void {
                    $owner = DB::table('workspace_user')->where('workspace_id', $workspaceId)->where('role', 'owner')->orderBy('id')->value('user_id');
                    if ($owner !== null) {
                        DB::table($table)->where('workspace_id', $workspaceId)->whereNull('user_id')->update(['user_id' => $owner]);
                    }
                });
        }

        $this->untangle();
    }

    /**
     * Before v1.5 a team's projects were shared: one person could file a recording under another person's project
     * (its notes and memory lines were written there), make a folder inside it, or point a request link at it. Each
     * person keeps what they made, so each of these moves to a top-level project of its own maker named like the other
     * one ("Acme", or "Acme (2)" when they have an "Acme" already), made once per project and person. Nothing is
     * deleted, and the other person's project keeps its own brief, files, rules and contacts.
     */
    private function untangle(): void
    {
        $copies = [];
        $copyFor = function (string $projectId, int $userId) use (&$copies): string {
            $key = "{$projectId}:{$userId}";
            if (isset($copies[$key])) {
                return $copies[$key];
            }
            $project = DB::table('clients')->where('id', $projectId)->first();
            $base = mb_substr(trim((string) $project->name), 0, 110);
            $name = $base;
            $taken = fn (string $candidate): bool => DB::table('clients')->where('workspace_id', $project->workspace_id)->where('user_id', $userId)
                ->whereNull('parent_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->exists();
            for ($n = 2; $taken($name); $n++) {
                $name = "{$base} ({$n})";
            }
            $id = strtolower((string) Str::ulid());
            $now = now();
            DB::table('clients')->insert([
                'id' => $id, 'workspace_id' => $project->workspace_id, 'user_id' => $userId, 'parent_id' => null, 'name' => $name,
                'color' => $project->color, 'avatar' => $project->avatar, 'language' => $project->language, 'retention_days' => $project->retention_days,
                'ai_notes' => $project->ai_notes, 'screens' => $project->screens, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return $copies[$key] = $id;
        };

        // Folders inside another person's project.
        $folders = DB::table('clients as c')->join('clients as p', 'p.id', '=', 'c.parent_id')->whereColumn('c.user_id', '!=', 'p.user_id')
            ->orderBy('c.id')->get(['c.id', 'c.user_id', 'c.parent_id']);
        foreach ($folders as $folder) {
            DB::table('clients')->where('id', $folder->id)->update(['parent_id' => $copyFor($folder->parent_id, (int) $folder->user_id)]);
        }
        // Recordings filed under another person's project, those in the bin too (a restore then lands in the copy).
        $filed = DB::table('capture_sessions as s')->join('clients as c', 'c.id', '=', 's.client_id')->whereColumn('s.user_id', '!=', 'c.user_id')
            ->select('s.client_id', 's.user_id')->distinct()->orderBy('s.client_id')->get();
        foreach ($filed as $row) {
            DB::table('capture_sessions')->where('client_id', $row->client_id)->where('user_id', $row->user_id)
                ->update(['client_id' => $copyFor($row->client_id, (int) $row->user_id)]);
        }
        // Notes and memory lines of such a recording, also when it was moved out of that project since: to the project it
        // is in now (always its maker's after the step above), or else to the copy.
        $notes = DB::table('call_notes as n')->join('capture_sessions as s', 's.id', '=', 'n.capture_session_id')->join('clients as c', 'c.id', '=', 'n.client_id')
            ->whereColumn('s.user_id', '!=', 'c.user_id')->orderBy('n.id')->get(['n.id', 'n.client_id', 's.user_id', 's.client_id as filed_in']);
        foreach ($notes as $note) {
            DB::table('call_notes')->where('id', $note->id)->update(['client_id' => $note->filed_in ?? $copyFor($note->client_id, (int) $note->user_id)]);
        }
        $lines = DB::table('client_memory_items as m')->join('capture_sessions as s', 's.id', '=', 'm.source_id')->join('clients as c', 'c.id', '=', 'm.client_id')
            ->where('m.source_type', 'call')->whereColumn('s.user_id', '!=', 'c.user_id')->orderBy('m.id')->get(['m.id', 'm.client_id', 's.user_id', 's.client_id as filed_in']);
        foreach ($lines as $line) {
            DB::table('client_memory_items')->where('id', $line->id)->update(['client_id' => $line->filed_in ?? $copyFor($line->client_id, (int) $line->user_id)]);
        }
        // Request links into another person's project file into the copy (they would file into the Inbox otherwise).
        $links = DB::table('request_links as r')->join('clients as c', 'c.id', '=', 'r.client_id')->whereColumn('r.user_id', '!=', 'c.user_id')
            ->orderBy('r.id')->get(['r.id', 'r.client_id', 'r.user_id']);
        foreach ($links as $link) {
            DB::table('request_links')->where('id', $link->id)->update(['client_id' => $copyFor($link->client_id, (int) $link->user_id)]);
        }
    }

    public function down(): void
    {
        // The B20 index wants every rule unique in the workspace again.
        $clash = DB::table('filing_rules')->select('workspace_id', 'kind', 'match_value')->groupBy('workspace_id', 'kind', 'match_value')->havingRaw('COUNT(*) > 1')->first();
        if ($clash !== null) {
            throw new RuntimeException("Two people of workspace {$clash->workspace_id} have the {$clash->kind} rule \"{$clash->match_value}\": remove one of them before rolling back.");
        }
        Schema::table('filing_rules', function (Blueprint $table): void {
            $table->dropUnique('filing_rules_person_rule_unique');
            $table->unique(['workspace_id', 'kind', 'match_value']);
        });
        Schema::table('filing_rules', function (Blueprint $table): void {
            $table->dropIndex('filing_rules_workspace_id_index');
            $table->dropColumn('user_id');
        });

        $personal = DB::table('plans')->where('key', 'personal')->value('id');
        $free = DB::table('plans')->where('key', 'free')->value('id');
        if ($free !== null && $personal !== null) {
            DB::table('workspaces')->where('plan_id', $free)->update(['plan_id' => $personal]);
        }
        if ($free !== null && ! DB::table('workspaces')->where('plan_id', $free)->exists()) {
            DB::table('plans')->where('id', $free)->delete();
        }
        // Every other plan stays; the B20 code reads only its minutes, storage and devices.
        if ($personal !== null) {
            foreach (self::B20_PERSONAL as $column => $value) {
                DB::table('plans')->where('id', $personal)->whereNull($column)->update([$column => $value]);
            }
        }
        // Roles B20 does not know act as members there (owners stay owners).
        DB::table('workspace_user')->whereIn('role', ['admin', 'viewer'])->update(['role' => 'member']);
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropColumn('stored_bytes');
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['price_usd', 'retention_days', 'seats', 'ai_credits_usd', 'features']);
        });
    }
};
