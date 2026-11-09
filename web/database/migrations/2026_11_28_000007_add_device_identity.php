<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per phone or computer (since v1.6, P3, docs/API.md `install_id`): the app's own id of its install, so the same
 * install signing in again reuses its row; whether the person chose the name (Rename); and when and by whom it was
 * signed out (the row stays, as "Signed out by you" or "Revoked by admin", until it is removed from the list).
 *
 * Existing duplicates are merged: rows of one person with the same platform and the same name (an app installed again
 * signed in as a new device). The newest row (last seen, else made) stays with its token; each older row's recordings,
 * usage rows, pairing codes, audit entries and device check move to it, and the older row and its tokens go. A row
 * whose recordings would clash with the kept one's (the same `client_ref`) is left as it was. What was merged is kept in
 * `device_merges`, which down() uses to put the older rows back (signed out: their tokens are gone) with what moved.
 */
return new class extends Migration
{
    private const MORPH = 'App\\Models\\Device';

    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('install_id', 128)->nullable();
            $table->boolean('name_chosen')->default(false);
            $table->timestamp('signed_out_at')->nullable();
            $table->string('signed_out_by', 8)->nullable();   // self | admin
            $table->index(['user_id', 'install_id']);
        });
        Schema::create('device_merges', function (Blueprint $table): void {
            $table->id();
            $table->string('kept_id', 26);
            $table->json('device');      // the older row as it was
            $table->json('moved');       // ids that now point at the kept row, per table
            $table->timestamps();
        });

        $groups = DB::table('devices')->select('user_id', 'platform', 'name')->groupBy('user_id', 'platform', 'name')->havingRaw('COUNT(*) > 1')->get();
        foreach ($groups as $group) {
            $rows = DB::table('devices')->where(['user_id' => $group->user_id, 'platform' => $group->platform, 'name' => $group->name])->get()
                ->sortByDesc(fn ($d): string => (string) ($d->last_seen_at ?? $d->created_at).'|'.$d->id)->values();
            $kept = $rows->shift();
            foreach ($rows as $old) {
                $this->merge($old, $kept);
            }
        }
    }

    private function merge(object $old, object $kept): void
    {
        $refs = DB::table('capture_sessions')->where('device_id', $old->id)->pluck('client_ref');
        if ($refs->isNotEmpty() && DB::table('capture_sessions')->where('device_id', $kept->id)->whereIn('client_ref', $refs->all())->exists()) {
            return; // the same recording id on both: keep both rows rather than lose one
        }
        DB::transaction(function () use ($old, $kept): void {
            $moved = [
                'capture_sessions' => DB::table('capture_sessions')->where('device_id', $old->id)->pluck('id')->all(),
                'usage_ledger' => DB::table('usage_ledger')->where('device_id', $old->id)->pluck('id')->all(),
                'device_pairing_codes' => DB::table('device_pairing_codes')->where('device_id', $old->id)->pluck('id')->all(),
                'audit_events' => DB::table('audit_events')->where('device_id', $old->id)->pluck('id')->all(),
                'device_reports' => [],
            ];
            foreach (['capture_sessions', 'usage_ledger', 'device_pairing_codes', 'audit_events'] as $table) {
                DB::table($table)->where('device_id', $old->id)->update(['device_id' => $kept->id]);
            }
            // One device check per device: the kept row's own stays, else the older row's moves to it.
            $report = DB::table('device_reports')->where('device_id', $old->id)->first();
            if ($report !== null && ! DB::table('device_reports')->where('device_id', $kept->id)->exists()) {
                DB::table('device_reports')->where('id', $report->id)->update(['device_id' => $kept->id]);
                $moved['device_reports'][] = $report->id;
            }
            DB::table('personal_access_tokens')->where('tokenable_type', self::MORPH)->where('tokenable_id', $old->id)->delete();
            DB::table('device_merges')->insert(['kept_id' => $kept->id, 'device' => json_encode((array) $old), 'moved' => json_encode($moved), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('devices')->where('id', $old->id)->delete();
        });
    }

    public function down(): void
    {
        foreach (DB::table('device_merges')->orderByDesc('id')->get() as $merge) {
            $device = (array) json_decode((string) $merge->device, true);
            $moved = (array) json_decode((string) $merge->moved, true);
            unset($device['install_id'], $device['name_chosen'], $device['signed_out_at'], $device['signed_out_by']);
            if (! DB::table('devices')->where('id', $device['id'] ?? '')->exists() && DB::table('users')->where('id', $device['user_id'] ?? 0)->exists()) {
                DB::table('devices')->insert($device);
            }
            foreach (['capture_sessions', 'usage_ledger', 'device_pairing_codes', 'audit_events', 'device_reports'] as $table) {
                $ids = (array) ($moved[$table] ?? []);
                if ($ids !== [] && DB::table('devices')->where('id', $device['id'] ?? '')->exists()) {
                    DB::table($table)->whereIn('id', $ids)->where('device_id', $merge->kept_id)->update(['device_id' => $device['id']]);
                }
            }
        }
        Schema::dropIfExists('device_merges');
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'install_id']);
            $table->dropColumn(['install_id', 'name_chosen', 'signed_out_at', 'signed_out_by']);
        });
    }
};
