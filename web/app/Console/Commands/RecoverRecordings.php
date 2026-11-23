<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Jobs\AssembleRecordingJob;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finishes recordings left in `assembling` by a request or worker that died mid-assembly (docs/API.md "complete"):
 * every part still on disk → assemble again on the queue; parts gone but the encrypted file written → keep it when it
 * verifies; otherwise → `failed`, so the device uploads the file again. Since W-INC-1124 the rounds of one assembly
 * are spaced and bounded (ChunkedUploadService::roundWaitMinutes, ::pastKeeping): past the bound → `failed` as well.
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
                // W-INC-1124: parts kept after a fault of the server get their rounds, then the device sends the file again.
                if ($uploads->pastKeeping($recording)) {
                    $uploads->discard($recording, ChunkedUploadService::NOT_FINISHED);
                    Log::warning('Assembly gave up after a lasting fault of the server; the device is asked for the file again.', [
                        'recording' => $recording->id, 'rounds' => (int) $recording->recovery_rounds,
                    ]);

                    return 'failed';
                }
                $rounds = (int) $recording->recovery_rounds;
                if ($rounds > 0 && $recording->updated_at->gt(now()->subMinutes(ChunkedUploadService::roundWaitMinutes($rounds)))) {
                    return null; // its next round is not due yet
                }
                // Counting the round also starts the waiting period again, so a queue that is merely slow is not
                // flooded with copies.
                $recording->forceFill(['recovery_rounds' => $rounds + 1, 'assembling_since' => $recording->assembling_since ?? now()])->save();
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
