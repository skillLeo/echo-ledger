<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\Views\TranscriptViews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Show as" (since v1.2): makes the Roman Urdu and English views of one transcript. Runs once; a failure is stored. */
final class MakeTranscriptViewsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $transcriptId) {}

    public function handle(TranscriptViews $views): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript === null) {
            return;
        }
        $views->make($transcript);
        // Accuracy Lab (since v1.5): a comparison run's Roman Urdu view is scored against the Roman Urdu reference.
        if ($transcript->kind === TranscriptKind::Trial
            && CaptureSession::query()->withoutGlobalScopes()->whereKey($transcript->capture_session_id)->whereNotNull('reference_roman')->exists()) {
            ScoreAccuracyJob::dispatch($transcript->capture_session_id);
        }
    }
}
