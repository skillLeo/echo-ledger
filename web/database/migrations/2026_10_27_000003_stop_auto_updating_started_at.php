<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MySQL / MariaDB with `explicit_defaults_for_timestamp` off give the first NOT NULL TIMESTAMP column of a table
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, so every update of a session moved its `started_at` to
 * the time of the update (likewise a used pairing code's `expires_at` and an updated usage row's `occurred_at`). The columns get an
 * explicit default without ON UPDATE, and sessions whose start was moved past their end get it back from the end,
 * the duration and the paused time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE capture_sessions MODIFY started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
            DB::statement('ALTER TABLE device_pairing_codes MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
            DB::statement('ALTER TABLE usage_ledger MODIFY occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        $this->repairMovedStarts();
    }

    public function down(): void
    {
        // Nothing to undo: putting ON UPDATE back would bring the bug back.
    }

    /** A start after the end is impossible: it was moved by an update. The real one is end − duration − pauses. */
    private function repairMovedStarts(): void
    {
        DB::table('capture_sessions')
            ->whereNotNull('ended_at')
            ->whereNotNull('duration_ms')
            ->whereColumn('started_at', '>', 'ended_at')
            ->orderBy('id')
            ->select(['id', 'ended_at', 'duration_ms', 'pauses'])
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $paused = 0;
                    $pauses = is_string($row->pauses) ? json_decode($row->pauses, true) : null;
                    foreach (is_array($pauses) ? $pauses : [] as $pause) {
                        $paused += is_array($pause) ? (int) ($pause['paused_ms'] ?? 0) : 0;
                    }
                    $start = Carbon::parse((string) $row->ended_at)->subMilliseconds((int) $row->duration_ms + $paused);
                    DB::table('capture_sessions')->where('id', $row->id)->update(['started_at' => $start->format('Y-m-d H:i:s')]);
                }
            });
    }
};
