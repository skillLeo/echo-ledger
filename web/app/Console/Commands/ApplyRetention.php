<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RetentionPolicy;
use Illuminate\Console\Command;

/** Hourly (plan B17): recordings past their retention go to the undo bin, and the bin erases what is 48 hours old. */
final class ApplyRetention extends Command
{
    protected $signature = 'recordings:retention';

    protected $description = 'Move recordings past their retention to the undo bin and empty what has waited 48 hours';

    public function handle(RetentionPolicy $policy): int
    {
        $moved = $policy->applyRetention();
        $erased = $policy->emptyBin();
        $this->info("{$moved} moved to the bin, {$erased} erased.");

        return self::SUCCESS;
    }
}
