<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SessionLifecycle;
use Illuminate\Console\Command;

/**
 * Tombstones sessions still `recording` after the given age with no recording attached (the app crashed, lost the
 * session or was removed). Devices learn about them through `GET /api/library?since=` like any delete.
 */
final class SweepOpenSessions extends Command
{
    protected $signature = 'sessions:sweep-open {--hours=6 : Age at which an open session without recordings is removed}';

    protected $description = 'Delete open sessions that were never finished';

    public function handle(SessionLifecycle $lifecycle): int
    {
        $swept = $lifecycle->sweepStale(max(1, (int) $this->option('hours')));
        $this->info("Swept {$swept} open session(s).");

        return self::SUCCESS;
    }
}
