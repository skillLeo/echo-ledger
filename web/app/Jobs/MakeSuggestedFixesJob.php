<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\ClientAi\ClientWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Suggested fixes of a call on a plan without AI notes (since v1.5): a plan never switches them off (docs/API.md "Plans
 * and features"), and with AI notes in the plan they come with the call notes.
 */
final class MakeSuggestedFixesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $sessionId) {}

    public function handle(ClientWorkspace $workspace): void
    {
        $session = CaptureSession::query()->withoutGlobalScopes()->find($this->sessionId);
        $client = $session !== null ? $workspace->projectOf($session) : null;
        $transcript = $session?->finalTranscript()->first();
        if ($client !== null && $transcript instanceof Transcript && $transcript->status === TranscriptStatus::Completed) {
            $workspace->suggestFixes($transcript, $client);
        }
    }
}
