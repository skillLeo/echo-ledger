<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Plans, roles and privacy (since v1.5, docs/API.md "Workspaces, roles and privacy" and "Plans and features"): plans
 * gain a price (0 until billing comes), retention days, seats, AI credits per month and their features; the Personal
 * plan every existing workspace is on has no limits and every feature, and a Free plan is added for new sign-ups.
 * Roles admin and viewer need no schema change (`workspace_user.role` is a string). Screens record what their two
 * encrypted files take, for the plan's storage. Content keeps the `user_id` of the person who made it; a row without
 * one (none is expected) goes to the first owner of its workspace.
 *
 * Rollback moves workspaces on Free back to Personal, deletes Free, gives Personal its B20 limits again where they are
 * empty and drops the new columns; nothing of the library changes.
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
