<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientAi\ClientWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Makes a client's brief again after its files or (without files) its calls changed (v1.4, P4); an edited brief stays. */
final class RefreshClientBriefJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Several uploads at once make one brief, not one each. */
    public int $uniqueFor = 120;

    public function __construct(public readonly string $clientId, public readonly bool $force = false) {}

    public function uniqueId(): string
    {
        return $this->clientId.($this->force ? ':asked' : '');
    }

    public function handle(ClientWorkspace $workspace): void
    {
        $client = Client::query()->withoutGlobalScopes()->find($this->clientId);
        if ($client !== null) {
            $workspace->refreshBrief($client, $this->force);
        }
    }
}
