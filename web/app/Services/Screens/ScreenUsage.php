<?php

declare(strict_types=1);

namespace App\Services\Screens;

use App\Models\Screen;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Screen moments' real sizes (v1.8 P4.1, "report the real average size per meeting hour"), for Admin → Server usage:
 * over the recordings with screens that started in a period, how many screens they kept, the average picture, and
 * the screens and megabytes per hour of meeting. Numbers only.
 */
final class ScreenUsage
{
    /**
     * @return array{recordings: int, screens: int, hours: float, avg_bytes: int|null, avg_stored_bytes: int|null, screens_per_hour: float|null, mb_per_hour: float|null}
     */
    public function between(Carbon $from, Carbon $to, ?int $workspaceId = null): array
    {
        $rows = Screen::query()->withoutGlobalScopes()
            ->join('capture_sessions', 'capture_sessions.id', '=', 'screens.capture_session_id')
            ->where('capture_sessions.started_at', '>=', $from->copy()->utc())->where('capture_sessions.started_at', '<', $to->copy()->utc())
            ->when($workspaceId !== null, fn ($q) => $q->where('capture_sessions.workspace_id', $workspaceId))
            ->groupBy('screens.capture_session_id')
            ->select('screens.capture_session_id', DB::raw('COUNT(*) AS screens'), DB::raw('SUM(screens.bytes) AS bytes'),
                DB::raw('SUM(COALESCE(screens.stored_bytes, screens.bytes)) AS stored'), DB::raw('MAX(capture_sessions.duration_ms) AS duration'))
            ->toBase()->get();
        $screens = (int) $rows->sum('screens');
        $bytes = (int) $rows->sum('bytes');
        $stored = (int) $rows->sum('stored');
        $hours = (float) $rows->sum(fn (object $r): int => (int) ($r->duration ?? 0)) / 3_600_000;

        return [
            'recordings' => $rows->count(),
            'screens' => $screens,
            'hours' => round($hours, 2),
            'avg_bytes' => $screens > 0 ? (int) round($bytes / $screens) : null,
            'avg_stored_bytes' => $screens > 0 ? (int) round($stored / $screens) : null,
            'screens_per_hour' => $hours > 0 ? round($screens / $hours, 1) : null,
            'mb_per_hour' => $hours > 0 ? round($stored / 1_048_576 / $hours, 1) : null,
        ];
    }
}
