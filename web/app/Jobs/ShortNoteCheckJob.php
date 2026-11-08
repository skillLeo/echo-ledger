<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\ShortNoteCheck;
use App\Services\Views\TranscriptViews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Double-check short notes (since v1.5, Phase 1C), then the views: they are made after the check, so a word the
 * engines disagree on reaches them marked unsure (they never translate a guess). Runs once: what was sent is billed.
 */
final class ShortNoteCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $transcriptId) {}

    public function handle(ShortNoteCheck $check, TranscriptViews $views): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript === null) {
            return;
        }
        $check->run($transcript);
        $views->autoMake($transcript->refresh());
    }
}
