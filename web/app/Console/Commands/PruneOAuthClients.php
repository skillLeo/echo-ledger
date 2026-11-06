<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Connector\ConnectedApps;
use Illuminate\Console\Command;

/** Removes apps that registered for the connector a week ago and never got a token (since v1.5). Daily. */
final class PruneOAuthClients extends Command
{
    protected $signature = 'echoledger:prune-oauth-clients';

    protected $description = 'Delete connector apps that registered more than a day ago and never connected';

    public function handle(ConnectedApps $apps): int
    {
        $this->info('Removed '.$apps->pruneClients().' app(s).');

        return self::SUCCESS;
    }
}
