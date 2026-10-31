<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CaptureSession;
use App\Services\ClientAi\ClientWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The notes of one client recording once its transcript is ready (v1.4, P4); then its memory and, without files, the brief. */
final class MakeCallNotesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $sessionId) {}

    public function handle(ClientWorkspace $workspace): void
    {
        $session = CaptureSession::query()->withoutGlobalScopes()->find($this->sessionId);
        if ($session !== null && $session->client_id !== null) {
            $workspace->makeNotes($session);
        }
    }
}
