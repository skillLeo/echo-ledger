<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\TranscriptStore;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\Exceptions\ProviderNotConfigured;
use App\Stt\SttManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Submits (async providers) or performs (sync providers) the final transcription.
 * Idempotent: never re-submits once a provider job id exists (a timed-out submit may still have been billed).
 */
final class TranscribeFinalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $transcriptId) {}

    public function handle(SttManager $stt, ChunkedUploadService $uploads, TranscriptStore $store): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript === null || $transcript->provider_job_id !== null || $transcript->status->isTerminal()
            || $transcript->status === TranscriptStatus::AwaitingDevice) {
            return;
        }

        $session = $transcript->captureSession;
        $recordings = $session->recordings()->get()->keyBy(fn ($r) => $r->role->value);
        $primary = $recordings->get(RecordingRole::Primary->value);
        if ($primary === null || ! $primary->isReady()) {
            $store->fail($transcript, 'Primary recording is missing or not ready.');

            return;
        }

        $provider = SttProviderKey::from($transcript->provider);
        $driver = $stt->driver($provider);
        $transcript->increment('attempts');
        $transcript->update(['status' => TranscriptStatus::Processing]);

        $temps = [];
        try {
            $primaryPath = $temps[] = $uploads->decryptToTemp($primary);
            $channel1 = $recordings->get(RecordingRole::Channel1->value);
            $channel2 = $recordings->get(RecordingRole::Channel2->value);
            $useChannels = $channel1?->isReady() && $channel2?->isReady() && $driver->capabilities()->needsChannelArtifacts;
            $channel1Path = $useChannels ? ($temps[] = $uploads->decryptToTemp($channel1)) : null;
            $channel2Path = $useChannels ? ($temps[] = $uploads->decryptToTemp($channel2)) : null;

            $result = $driver->submitFinal(new FinalJobRequest(
                transcript: $transcript,
                session: $session,
                primary: $primary,
                primaryPath: $primaryPath,
                channel1Path: $channel1Path,
                channel2Path: $channel2Path,
                channel1Mime: $useChannels ? $channel1->mime : null,
                channel2Mime: $useChannels ? $channel2->mime : null,
                keyterms: $stt->keytermsFor($session->client),
                language: $session->language ?: ($session->client?->language ?: null),
                webhookUrl: $stt->webhookUrl($provider, $transcript->id),
            ));
        } catch (ProviderJobFailed|ProviderNotConfigured $e) {
            $store->fail($transcript, $e->getMessage()); // permanent: do not retry

            return;
        } finally {
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }

        match ($result->state) {
            'submitted' => $transcript->update([
                'status' => TranscriptStatus::Submitted,
                'provider_job_id' => $result->jobId,
                'provider_transcript_id' => $result->transcriptId,
                'submitted_at' => now(),
            ]),
            'completed' => $store->complete($transcript->fill(['provider_job_id' => $result->jobId ?: null, 'submitted_at' => now()]), $result->result
                ?? throw new \LogicException('Completed result without transcript.')),
            'awaiting_device' => $transcript->update(['status' => TranscriptStatus::AwaitingDevice, 'submitted_at' => now()]),
            default => throw new \LogicException("Unknown submit state {$result->state}."),
        };
    }

    public function failed(?Throwable $exception): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript !== null && ! $transcript->status->isTerminal()) {
            $transcript->update(['status' => TranscriptStatus::Failed, 'error' => mb_substr($exception?->getMessage() ?? 'Transcription failed.', 0, 2000)]);
            $transcript->captureSession->update(['status' => SessionStatus::Failed]);
        }
    }
}
