<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CallRules\AudioCleanup;
use Illuminate\Console\Command;

/**
 * Daily (contract 2.1.0-a): audio nobody transcribed, past its retention, goes to Trash (which erases it for good after
 * trash.keep_hours); on Mondays, or with --summary, each person with audio going within a week gets one quiet
 * Activity entry.
 */
final class AudioCleanupCommand extends Command
{
    protected $signature = 'recordings:audio-cleanup {--summary : send the weekly summary whatever the day}';

    protected $description = 'Move audio-only recordings past their retention to Trash, and send the weekly summary on Mondays';

    public function handle(AudioCleanup $cleanup): int
    {
        $moved = $cleanup->run();
        $told = $this->option('summary') || now()->isMonday() ? $cleanup->weeklySummary() : 0;
        $this->info("{$moved} moved to Trash, {$told} weekly summaries.");

        return self::SUCCESS;
    }
}
