<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\ProviderCopyEraser;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Services\Usage\BudgetGuard;
use App\Services\Usage\UsageLedger;
use App\Services\Workspaces\PlanLimits;
use App\Stt\Contracts\SttProvider;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\Exceptions\ProviderNotConfigured;
use App\Stt\LanguageChoice;
use App\Stt\SttManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Submits (async providers) or performs (sync providers) the final transcription.
 *
 * Never submits twice: the transcript is claimed (pending → processing) right before the provider call, and every
 * error during that call fails the transcript with a reason instead of retrying, because a submit that timed out
 * may already be billed. Errors before the call leave it pending and propagate, so the queue (or, after an inline
 * attempt, the coordinator's queued fallback and stt:reconcile) picks it up again.
 */
final class TranscribeFinalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Smaller than any real recording: an Ogg/Opus header alone is ~100 bytes, one second of speech is several KB. */
    public const MIN_AUDIO_BYTES = 1024;

    public function __construct(public readonly string $transcriptId) {}

    public function handle(SttManager $stt, ChunkedUploadService $uploads, TranscriptStore $store): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript === null || $transcript->provider_job_id !== null || $transcript->status !== TranscriptStatus::Pending) {
            return; // gone, already submitted, finished, handed to the device, or another run holds it
        }

        $session = $transcript->captureSession;
        $recordings = $session->recordings()->get()->keyBy(fn ($r) => $r->role->value);
        $primary = $recordings->get(RecordingRole::Primary->value);
        if ($primary === null || ! $primary->isReady()) {
            $store->failWith($transcript, TranscriptErrorCode::RecordingMissing);

            return;
        }

        // A container with (almost) no audio frames means capture produced nothing. Fail with a clear, platform-neutral
        // message (each app adds its own hint) instead of a provider "transcoding failed" error.
        if ($primary->declared_size_bytes < self::MIN_AUDIO_BYTES) {
            $store->failWith($transcript, TranscriptErrorCode::NoAudio);

            return;
        }

        $provider = SttProviderKey::from($transcript->provider);
        // Monthly limit (A5 server cap or the workspace plan, B20): nothing is sent to a paid provider once it is reached
        // (Retry from the console after raising it).
        if (UsageLedger::isBillable($provider) && (app(BudgetGuard::class)->limitReached() || app(PlanLimits::class)->minutesReached($session->workspace_id))) {
            $store->failWith($transcript, TranscriptErrorCode::BudgetExceeded);

            return;
        }

        $driver = $stt->driver($provider);
        $temps = [];
        try {
            $primaryPath = $temps[] = $uploads->decryptToTemp($primary);
            $channel1 = $recordings->get(RecordingRole::Channel1->value);
            $channel2 = $recordings->get(RecordingRole::Channel2->value);
            $useChannels = $channel1?->isReady() && $channel2?->isReady() && $driver->capabilities()->needsChannelArtifacts;
            $channel1Path = $useChannels ? ($temps[] = $uploads->decryptToTemp($channel1)) : null;
            $channel2Path = $useChannels ? ($temps[] = $uploads->decryptToTemp($channel2)) : null;

            $request = new FinalJobRequest(
                transcript: $transcript,
                session: $session,
                primary: $primary,
                primaryPath: $primaryPath,
                channel1Path: $channel1Path,
                channel2Path: $channel2Path,
                channel1Mime: $useChannels ? $channel1->mime : null,
                channel2Mime: $useChannels ? $channel2->mime : null,
                keyterms: $stt->keytermsFor($session->client),
                // The transcript's own choice (set when it is started or re-transcribed), then the session's, the client's
                // and the console default.
                language: LanguageChoice::normalize($transcript->language) ?? LanguageChoice::normalize($session->language)
                    ?? LanguageChoice::normalize($session->client?->language) ?? LanguageChoice::normalize(app(SettingsRepository::class)->get('default_language')),
                webhookUrl: $stt->webhookUrl($provider, $transcript->id),
            );

            // Claim: only one run may submit, and only now does the transcript become "processing".
            $claimed = Transcript::query()->whereKey($transcript->id)
                ->where('status', TranscriptStatus::Pending->value)->whereNull('provider_job_id')
                ->increment('attempts', 1, ['status' => TranscriptStatus::Processing->value]);
            if ($claimed !== 1) {
                return;
            }
            $transcript->refresh();

            $result = $this->submit($driver, $request, $transcript, $store);
        } finally {
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }

        if ($result === null) {
            return;
        }

        if (! Transcript::query()->whereKey($transcript->id)->exists()) {
            $this->orphaned($transcript, $result);

            return;
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

    /** The provider call. Any error fails the transcript with its documented code; nothing is retried automatically. */
    private function submit(SttProvider $driver, FinalJobRequest $request, Transcript $transcript, TranscriptStore $store): ?SubmitResult
    {
        try {
            return $driver->submitFinal($request);
        } catch (ProviderNotConfigured $e) {
            $store->fail($transcript, $e->getMessage(), TranscriptErrorCode::ProviderNotConfigured);
        } catch (ProviderJobFailed $e) {
            $store->fail($transcript, TranscriptErrorCode::rejectedMessage($e->getMessage()), TranscriptErrorCode::ProviderRejected);
        } catch (RequestException $e) {
            $store->fail($transcript, TranscriptErrorCode::rejectedMessage(self::providerReason($e)), TranscriptErrorCode::ProviderRejected);
        } catch (ConnectionException) {
            $store->failWith($transcript, TranscriptErrorCode::ProviderUnreachable);
        } catch (Throwable $e) {
            report($e);
            $store->failWith($transcript, TranscriptErrorCode::Interrupted);
        }

        return null;
    }

    /** The provider's own words for a refused request (its JSON error field), else the HTTP status. */
    public static function providerReason(RequestException $e): string
    {
        $response = $e->response;
        $json = $response->json();
        if (is_array($json)) {
            $detail = $json['detail'] ?? null;
            foreach ([$json['error'] ?? null, $json['message'] ?? null, is_array($detail) ? ($detail['message'] ?? null) : $detail] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return $candidate;
                }
            }
        }

        return 'HTTP '.$response->status();
    }

    /**
     * The session was deleted while the provider worked: erase the provider's copy (or queue it), and keep the
     * provider minutes of a finished job in the usage ledger, since they were billed.
     */
    private function orphaned(Transcript $transcript, SubmitResult $result): void
    {
        if ($result->state === 'completed' && $result->result !== null) {
            app(UsageLedger::class)->recordFinal($transcript->forceFill(['provider_job_id' => $result->jobId ?: null]), $result->result);
        }
        if ($result->jobId !== null && $result->jobId !== '') {
            app(ProviderCopyEraser::class)->erase($transcript->forceFill([
                'provider_job_id' => $result->jobId,
                'provider_transcript_id' => $result->transcriptId ?? $transcript->provider_transcript_id,
            ]));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript !== null && ! $transcript->status->isTerminal()) {
            $transcript->update([
                'status' => TranscriptStatus::Failed,
                'error' => mb_substr($exception?->getMessage() ?? 'Transcription failed.', 0, 2000),
                'error_code' => TranscriptErrorCode::Interrupted->value,
            ]);
            if ($transcript->kind === TranscriptKind::Final) {
                $transcript->captureSession->update(['status' => SessionStatus::Failed]);
            }
        }
    }
}
