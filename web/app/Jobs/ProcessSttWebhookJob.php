<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SttProviderKey;
use App\Enums\TranscriptErrorCode;
use App\Models\WebhookEvent;
use App\Services\ProviderCopyEraser;
use App\Services\TranscriptStore;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\SttManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Fetches the finished transcript for a verified webhook, stores it in one transaction, THEN deletes the
 * provider-side copy (transcript + uploaded audio) and records provider_deleted_at.
 */
final class ProcessSttWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(SttManager $stt, TranscriptStore $store): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || $event->processed_at !== null || $event->transcript === null) {
            return;
        }
        $transcript = $event->transcript;
        if ($transcript->status->isTerminal()) {
            $event->update(['status' => 'processed', 'processed_at' => now()]);

            return;
        }

        $driver = $stt->driver(SttProviderKey::from($event->provider));
        try {
            $result = $driver->fetchResult($transcript, $event->payload);
        } catch (ProviderJobFailed $e) {
            $store->fail($transcript, TranscriptErrorCode::rejectedMessage($e->getMessage()), TranscriptErrorCode::ProviderRejected);
            $this->deleteRemoteQuietly($stt, $event);
            $event->update(['status' => 'processed', 'processed_at' => now(), 'payload' => null]);

            return;
        }

        if ($result === null) {
            // Provider not finished yet. Under inline (sync) execution release() would re-run immediately, so hand
            // the retry to the real queue instead.
            if ($this->job !== null && $this->job->getConnectionName() === 'sync') {
                self::dispatch($this->webhookEventId)->delay(now()->addSeconds(60));
            } else {
                $this->release(60);
            }

            return;
        }

        $store->complete($transcript, $result);
        $this->deleteRemoteQuietly($stt, $event);
        // Drop the (encrypted) raw payload once normalized data is stored.
        $event->update(['status' => 'processed', 'processed_at' => now(), 'payload' => null]);
    }

    private function deleteRemoteQuietly(SttManager $stt, WebhookEvent $event): void
    {
        $transcript = $event->transcript;
        if ($transcript === null) {
            return;
        }
        try {
            $stt->driver(SttProviderKey::from($event->provider))->deleteRemote($transcript);
            $transcript->update(['provider_deleted_at' => now()]);
        } catch (Throwable $e) {
            ProviderCopyEraser::noteFailure($e, $event->provider); // retried by stt:reconcile (provider_deleted_at stays null)
        }
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'Webhook processing failed.', 0, 2000),
        ]);
    }
}
