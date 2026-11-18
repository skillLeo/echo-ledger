<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Assist\Http\CallNotes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The facts ledger of a Live Assist call in HTTP mode (docs/API_ASSIST.md "Practice, facts and summary"): after every
 * few final lines, the new facts in them, on the Fast model, within the budget (App\Services\Assist\Http\CallNotes).
 * Never holds up a suggestion: it runs on the queue.
 */
final class UpdateLiveFactsJob implements ShouldQueue
{
    use Queueable;

    /** Once: a retry would bill the model again. */
    public int $tries = 1;

    /** More than one model call (45 s) and the settling after it. */
    public int $timeout = 90;

    public function __construct(public readonly string $sessionId) {}

    public function handle(CallNotes $notes): void
    {
        $notes->facts($this->sessionId);
    }
}
