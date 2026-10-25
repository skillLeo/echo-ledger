<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B20 step 2 of 3: the plan "Personal", one workspace per user (owner role) and every owned row moved into its
 * user's workspace. The first super admin's workspace is the default one. One workspace per user keeps exactly
 * today's isolation (every query filtered by `user_id`). Query builder only (no model events), in chunks, and safe
 * to run again: rows that already have a workspace are left alone.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    private const SCOPED = ['devices', 'device_pairing_codes', 'clients', 'capture_sessions', 'usage_ledger'];

    public function up(): void
    {
        $now = now();
        $planId = DB::table('plans')->where('key', 'personal')->value('id');
        if ($planId === null) {
            $planId = DB::table('plans')->insertGetId([
                'key' => 'personal', 'name' => 'Personal', 'monthly_minutes' => 30000,
                'storage_bytes' => 500 * 1024 ** 3, 'max_devices' => 25, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $default = DB::table('users')->where('is_super_admin', true)->orderBy('id')->value('id');
        $workspaceOf = [];
        DB::table('users')->orderBy('id')->select(['id', 'name', 'current_workspace_id'])
            ->chunkById(self::CHUNK, function ($users) use ($default, $planId, $now, &$workspaceOf): void {
                foreach ($users as $user) {
                    $owned = DB::table('workspace_user')->where('user_id', $user->id)->where('role', 'owner')->orderBy('id')->value('workspace_id');
                    if ($owned === null) {
                        $owned = DB::table('workspaces')->insertGetId([
                            'name' => (int) $user->id === (int) $default ? 'Personal' : mb_substr(trim($user->name.' workspace'), 0, 120),
                            'plan_id' => $planId, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        DB::table('workspace_user')->insert([
                            'workspace_id' => $owned, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                    if ($user->current_workspace_id === null) {
                        DB::table('users')->where('id', $user->id)->update(['current_workspace_id' => $owned]);
                    }
                    $workspaceOf[(int) $user->id] = (int) $owned;
                }
            });

        foreach (self::SCOPED as $table) {
            foreach ($workspaceOf as $userId => $workspaceId) {
                do {
                    $ids = DB::table($table)->whereNull('workspace_id')->where('user_id', $userId)->limit(self::CHUNK)->pluck('id');
                    if ($ids->isNotEmpty()) {
                        DB::table($table)->whereIn('id', $ids->all())->update(['workspace_id' => $workspaceId]);
                    }
                } while ($ids->count() === self::CHUNK);
            }
        }
    }

    public function down(): void
    {
        foreach (self::SCOPED as $table) {
            do {
                $ids = DB::table($table)->whereNotNull('workspace_id')->limit(self::CHUNK)->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table($table)->whereIn('id', $ids->all())->update(['workspace_id' => null]);
                }
            } while ($ids->count() === self::CHUNK);
        }
        DB::table('users')->whereNotNull('current_workspace_id')->update(['current_workspace_id' => null]);
        DB::table('workspace_user')->delete();
        DB::table('workspaces')->delete();
        DB::table('plans')->delete();
    }
};
