<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Exceptions\AssemblyDataFault;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use App\Services\Storage\RecordingFiles;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /**
     * On a bucket the tries wait: a few seconds of trouble there are routine, and three tries in a row would end in
     * failed() and leave the recording to recordings:recover for 15 minutes. On the server's own disk they follow at once.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return app(RecordingFiles::class)->isLocal() ? [] : [60, 300];
    }

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

            try {
                $uploads->assemble($recording);
            } catch (AssemblyDataFault) {
                // The upload itself is bad: another try cannot help, so the device is asked for the file at once.
                $uploads->discard($recording, ChunkedUploadService::NOT_FINISHED);
            }
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
     * Every attempt failed. Only a fault of the upload itself (a part missing or not decrypting) discards the parts, so
     * the device uploads the file again (docs/API.md); the reason is a fixed text and the exception goes to the log,
     * never to the device. Any other fault is the server's (a missing PHP extension, an error in the code, a disk or
     * bucket that fails): the parts stay and the recording stays `assembling`, so recordings:recover or a retry of
     * this job finishes it once the server is fixed, with no new upload (W-INC-1124: a hosting change took sodium out
     * of PHP, and six recordings were thrown away here).
     */
    public function failed(?Throwable $exception): void
    {
        $recording = Recording::query()->find($this->recordingId);
        if ($recording === null || $recording->upload_status !== UploadStatus::Assembling) {
            return;
        }
        if ($exception instanceof AssemblyDataFault) {
            app(ChunkedUploadService::class)->discard($recording, ChunkedUploadService::NOT_FINISHED);

            return;
        }
        Log::warning('Assembly failed on a server fault; the parts are kept for recordings:recover.', [
            'recording' => $recording->id, 'fault' => $exception === null ? null : $exception::class,
        ]);
    }
}
