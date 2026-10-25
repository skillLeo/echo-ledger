<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Jobs\AssembleRecordingJob;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finishes recordings left in `assembling` by a request or worker that died mid-assembly (docs/API.md "complete"):
 * every part still on disk → assemble again on the queue; parts gone but the encrypted file written → keep it when it
 * verifies; otherwise → `failed`, so the device uploads the file again.
 */
final class RecoverRecordings extends Command
{
    protected $signature = 'recordings:recover {--minutes=15 : Only recordings assembling for longer than this} {--limit=100}';

    protected $description = 'Finish or fail recordings whose assembly was interrupted';

    public function handle(ChunkedUploadService $uploads, FinalTranscriptCoordinator $coordinator): int
    {
        $counts = ['requeued' => 0, 'ready' => 0, 'failed' => 0];

        Recording::query()
            ->where('upload_status', UploadStatus::Assembling->value)
            ->where('updated_at', '<', now()->subMinutes(max(1, (int) $this->option('minutes'))))
            ->orderBy('updated_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (Recording $recording) use ($uploads, $coordinator, &$counts): void {
                try {
                    $outcome = $this->recover($recording, $uploads, $coordinator);
                    if ($outcome !== null) {
                        $counts[$outcome]++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Requeued {$counts['requeued']}, finished {$counts['ready']}, failed {$counts['failed']}.");

        return self::SUCCESS;
    }

    /** @return 'requeued'|'ready'|'failed'|null null when another process holds the recording or it moved on */
    private function recover(Recording $recording, ChunkedUploadService $uploads, FinalTranscriptCoordinator $coordinator): ?string
    {
        $lock = AssembleRecordingJob::lock($recording->id);
        if (! $lock->get()) {
            return null; // being assembled right now
        }

        try {
            $recording->refresh();
            if ($recording->upload_status !== UploadStatus::Assembling) {
                return null;
            }
            if ($uploads->missingChunks($recording) === []) {
                // Start the waiting period again, so a queue that is merely slow is not flooded with copies.
                $recording->touch();
                $outcome = 'requeued';
            } elseif ($uploads->adoptStoredFile($recording) === true) {
                $outcome = 'ready';
            } else {
                $uploads->discard($recording, ChunkedUploadService::NOT_FINISHED);

                return 'failed';
            }
        } finally {
            $lock->release();
        }

        // Outside the lock: on the sync queue the job runs inside dispatch() and takes the lock itself.
        if ($outcome === 'requeued') {
            AssembleRecordingJob::dispatch($recording->id);
        } else {
            $coordinator->maybeStart($recording->captureSession);
        }

        return $outcome;
    }
}
