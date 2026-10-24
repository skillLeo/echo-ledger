<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Verifies + encrypts an uploaded recording, then starts the final transcript when the session is complete. */
final class AssembleRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly string $recordingId) {}

    public function handle(ChunkedUploadService $uploads, FinalTranscriptCoordinator $coordinator): void
    {
        $recording = Recording::query()->find($this->recordingId);
        if ($recording === null || $recording->upload_status !== UploadStatus::Assembling) {
            return; // deleted meanwhile, or already assembled (idempotent)
        }

        $uploads->assemble($recording);
        $recording->refresh();

        if ($recording->upload_status === UploadStatus::Ready) {
            // When assembly ran inline (small recording), submit the final transcript inline too so it is not
            // waiting on the queue; a provider hiccup inside falls back to the queued path.
            $sync = $this->job?->getConnectionName() === 'sync';
            try {
                $coordinator->maybeStart($recording->captureSession, $sync);
            } catch (Throwable $e) {
                if (! $sync) {
                    throw $e;
                }
                report($e);
                $coordinator->maybeStart($recording->captureSession); // retry via the queue
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Recording::query()->whereKey($this->recordingId)->update([
            'upload_status' => UploadStatus::Failed,
            'error' => mb_substr($exception?->getMessage() ?? 'Assembly failed.', 0, 2000),
        ]);
    }
}
