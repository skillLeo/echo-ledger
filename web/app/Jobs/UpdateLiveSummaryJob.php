<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Assist\Http\CallNotes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The running summary of a Live Assist call in HTTP mode: at most every five minutes, when the call moved on, rewritten
 * from the last one and the new lines, on the Fast model, within the budget (App\Services\Assist\Http\CallNotes).
 */
final class UpdateLiveSummaryJob implements ShouldQueue
{
    use Queueable;

    /** Once: a retry would bill the model again. */
    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $sessionId) {}

    public function handle(CallNotes $notes): void
    {
        $notes->summary($this->sessionId);
    }
}
