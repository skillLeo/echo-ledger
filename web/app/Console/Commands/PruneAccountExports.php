<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Accounts\AccountExports;
use Illuminate\Console\Command;

/** Deletes "Export my data" ZIPs 24 hours after they were made (since v1.5). Hourly. */
final class PruneAccountExports extends Command
{
    protected $signature = 'echoledger:prune-exports';

    protected $description = 'Delete account exports older than 24 hours';

    public function handle(AccountExports $exports): int
    {
        $this->info('Removed '.$exports->prune().' export(s).');

        return self::SUCCESS;
    }
}
