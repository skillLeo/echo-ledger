<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SttProviderKey;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSttWebhookJob;
use App\Models\Transcript;
use App\Models\WebhookEvent;
use App\Stt\Exceptions\InvalidWebhookSignature;
use App\Stt\SttManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * POST /api/webhooks/stt/{provider} — public. Verify → record idempotently → respond fast → process in a job.
 * 401 on bad signatures (final, not retried); 5xx only for transient faults so the provider retries.
 */
final class SttWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, SttManager $stt): JsonResponse
    {
        $key = SttProviderKey::tryFrom($provider);
        if (! in_array($key, [SttProviderKey::AssemblyAi, SttProviderKey::ElevenLabs], true)) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        try {
            $envelope = $stt->driver($key)->verifyWebhook($request);
        } catch (InvalidWebhookSignature $e) {
            logger()->warning('Rejected STT webhook', ['provider' => $provider, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $transcript = $envelope->transcriptRef ? Transcript::query()->find($envelope->transcriptRef) : null;
        if ($transcript === null || $transcript->provider !== $key->value) {
            return response()->json(['received' => true, 'ignored' => 'unknown transcript']); // 2xx: never retry
        }
        if ($envelope->jobId !== null && $transcript->provider_job_id !== null && $transcript->provider_job_id !== $envelope->jobId) {
            return response()->json(['message' => 'Job id mismatch.'], 409);
        }

        try {
            $event = WebhookEvent::query()->createOrFirst(
                ['provider' => $key->value, 'idempotency_key' => $envelope->idempotencyKey],
                ['transcript_id' => $transcript->id, 'payload' => $envelope->payload, 'status' => 'received'],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Temporary failure.'], 503);
        }

        if ($event->wasRecentlyCreated) {
            // Process inline: the provider only calls back once the result is ready, so the device sees the final
            // transcript in seconds instead of waiting up to a minute for the next scheduled queue run. A transient
            // provider error falls back to the queue, which cron drains and stt:reconcile backstops.
            try {
                Bus::dispatchNow(new ProcessSttWebhookJob($event->id));
            } catch (Throwable $e) {
                report($e);
                ProcessSttWebhookJob::dispatch($event->id);
            }
        }

        return response()->json(['received' => true]);
    }
}
