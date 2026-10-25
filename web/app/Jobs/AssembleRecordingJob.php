<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Verifies + encrypts an uploaded recording, then starts the final transcript when the session is complete. */
final class AssembleRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** Longer than the job timeout, so a lock outlives only a worker that was killed mid-assembly. */
    public const LOCK_SECONDS = 900;

    public function __construct(public readonly string $recordingId) {}

    /** One assembly per recording at a time: the inline `complete`, the queued retry and `recordings:recover`. */
    public static function lock(string $recordingId): Lock
    {
        return Cache::lock('assemble:'.$recordingId, self::LOCK_SECONDS);
    }

    public function handle(ChunkedUploadService $uploads, FinalTranscriptCoordinator $coordinator): void
    {
        $lock = self::lock($this->recordingId);
        if (! $lock->get()) {
            return; // another process is assembling this recording right now
        }

        try {
            $recording = Recording::query()->find($this->recordingId);
            if ($recording === null || $recording->upload_status !== UploadStatus::Assembling) {
                return; // deleted meanwhile, or already assembled (idempotent)
            }

            $uploads->assemble($recording);
            $recording->refresh();
        } finally {
            $lock->release();
        }

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

    /**
     * Every attempt failed: discard the parts so the device uploads the file again (docs/API.md). The reason is a
     * fixed text; the exception itself goes to the log, never to the device.
     */
    public function failed(?Throwable $exception): void
    {
        $recording = Recording::query()->find($this->recordingId);
        if ($recording !== null && $recording->upload_status === UploadStatus::Assembling) {
            app(ChunkedUploadService::class)->discard($recording, ChunkedUploadService::NOT_FINISHED);
        }
    }
}
