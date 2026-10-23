<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CompletionMode;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\Transcript;
use App\Services\TranscriptStore;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\SttManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Safety net for missed webhooks (PLAN.md D11): polls stuck async jobs, re-queues lost submissions,
 * retries provider-side deletes, and fails jobs that never finish.
 */
final class ReconcileTranscripts extends Command
{
    protected $signature = 'stt:reconcile {--limit=50}';

    protected $description = 'Poll providers for transcripts whose webhook never arrived';

    public function handle(SttManager $stt, TranscriptStore $store): int
    {
        $limit = (int) $this->option('limit');

        // 1. Submitted async jobs with no webhook after 10 minutes.
        Transcript::query()
            ->whereIn('status', [TranscriptStatus::Submitted->value, TranscriptStatus::Processing->value])
            ->whereNotNull('provider_job_id')
            ->where('submitted_at', '<', now()->subMinutes(10))
            ->orderBy('submitted_at')->limit($limit)->get()
            ->each(function (Transcript $transcript) use ($stt, $store): void {
                $provider = SttProviderKey::tryFrom($transcript->provider);
                if ($provider === null) {
                    return;
                }
                $driver = $stt->driver($provider);
                if ($driver->capabilities()->mode !== CompletionMode::AsyncWebhook) {
                    return;
                }
                try {
                    $result = $driver->fetchResult($transcript);
                    if ($result !== null) {
                        $store->complete($transcript, $result);
                        $driver->deleteRemote($transcript);
                        $transcript->update(['provider_deleted_at' => now()]);
                        $this->info("Recovered transcript {$transcript->id}");
                    } elseif ($transcript->submitted_at?->lt(now()->subHours(6))) {
                        $store->fail($transcript, 'Provider did not finish within 6 hours.');
                    }
                } catch (ProviderJobFailed $e) {
                    $store->fail($transcript, $e->getMessage());
                } catch (Throwable $e) {
                    report($e);
                }
            });

        // 2. Pending transcripts whose job was lost (e.g. queue table cleared).
        Transcript::query()
            ->where('status', TranscriptStatus::Pending->value)
            ->whereNull('provider_job_id')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->limit($limit)->get()
            ->each(fn (Transcript $t) => TranscribeFinalJob::dispatch($t->id));

        // 3. Completed transcripts whose provider copy could not be deleted yet.
        Transcript::query()
            ->where('status', TranscriptStatus::Completed->value)
            ->whereNotNull('provider_job_id')
            ->whereNull('provider_deleted_at')
            ->whereIn('provider', [SttProviderKey::AssemblyAi->value, SttProviderKey::ElevenLabs->value])
            ->limit($limit)->get()
            ->each(function (Transcript $transcript) use ($stt): void {
                try {
                    $stt->driver(SttProviderKey::from($transcript->provider))->deleteRemote($transcript);
                    $transcript->update(['provider_deleted_at' => now()]);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return self::SUCCESS;
    }
}
