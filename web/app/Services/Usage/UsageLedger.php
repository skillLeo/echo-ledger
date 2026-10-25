<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\RecordingRole;
use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\FinalTranscriptCoordinator;
use App\Services\SettingsRepository;
use App\Stt\Data\NormalizedTranscript;
use Illuminate\Support\Carbon;

/**
 * Writes the usage ledger (docs/API.md "Usage and monthly limit"): streaming periods reported by devices and final
 * transcripts completed by a provider. `billed_ms = duration × billed channels`; `cost = billed_ms / 3 600 000 ×
 * price` with the console price at the time the row is written.
 */
final class UsageLedger
{
    /** Reports may arrive late (queued on a device) or with a slightly fast clock; anything outside is clamped. */
    public const MAX_REPORT_AGE_DAYS = 35;

    public const MAX_REPORT_LEAD_MINUTES = 5;

    private const MS_PER_HOUR = 3_600_000;

    public function __construct(private readonly SettingsRepository $settings) {}

    /** Self-hosted and on-device engines cost nothing per minute, so they never count against the monthly limit. */
    public static function isBillable(SttProviderKey $provider): bool
    {
        return ! in_array($provider, [SttProviderKey::WhisperCppLocal, SttProviderKey::ParakeetLocal], true);
    }

    /**
     * Stores one streaming report, idempotent on (owner, report_id): a repeat returns the stored row unchanged.
     *
     * @param  array{report_id: string, use_case: string, provider: string, session_id?: string|null, streamed_ms: int|string, channels: int|string, ended_at?: string|null}  $data  validated body
     * @return array{entry: UsageLedgerEntry, duplicate: bool}
     */
    public function recordStreaming(Device $device, array $data): array
    {
        $provider = SttProviderKey::from($data['provider']);
        $useCase = SttUseCase::from($data['use_case']);
        $streamed = (int) $data['streamed_ms'];
        $channels = (int) $data['channels'];
        $sessionId = empty($data['session_id']) ? null
            : CaptureSession::withTrashed()->where('workspace_id', $device->workspace_id)->find($data['session_id'])?->id;

        $entry = UsageLedgerEntry::query()->firstOrCreate(
            ['user_id' => $device->user_id, 'report_id' => $data['report_id']],
            [
                'workspace_id' => $device->workspace_id,
                'device_id' => $device->id,
                'capture_session_id' => $sessionId,
                'source' => UsageLedgerEntry::SOURCE_DEVICE,
                ...$this->pricing($provider, $useCase, $streamed, $channels),
                'model' => $this->streamingModel($provider),
                'occurred_at' => $this->clampedEnd($data['ended_at'] ?? null),
            ],
        );

        return ['entry' => $entry, 'duplicate' => ! $entry->wasRecentlyCreated];
    }

    /**
     * Stores the provider minutes of a completed final transcript. Returns the existing row when the same provider
     * job was recorded before (a webhook and the reconciler may both complete it).
     */
    public function recordFinal(Transcript $transcript, NormalizedTranscript $result): ?UsageLedgerEntry
    {
        $provider = $transcript->providerKey();
        // The session may already be soft-deleted when a late webhook completes the job.
        $session = CaptureSession::withTrashed()->find($transcript->capture_session_id);
        if ($provider === null || $session === null) {
            return null;
        }

        $useCase = FinalTranscriptCoordinator::useCaseFor($session);
        $primary = $session->recordings()->where('role', RecordingRole::Primary->value)->first();
        $meta = $result->meta;
        $recordingChannels = max(1, (int) ($primary->channels ?? 1));

        $reported = $meta['audio_duration_s'] ?? null;
        $duration = $provider === SttProviderKey::AssemblyAi && is_numeric($reported) && $reported > 0
            ? (int) round((float) $reported * 1000)
            : (int) ($primary->duration_ms ?? $session->duration_ms ?? 0);

        $channels = match ($provider) {
            SttProviderKey::AssemblyAi => is_numeric($meta['audio_channels'] ?? null) && $meta['audio_channels'] >= 1
                ? (int) $meta['audio_channels'] : $recordingChannels,
            // Each channel of a multichannel request is billed at the full duration.
            SttProviderKey::ElevenLabs => $recordingChannels,
            // The standard model bills one multiplexed file; MAI-Transcribe-2 is sent one file per channel.
            SttProviderKey::AzureMai => ($meta['model'] ?? null) === 'standard' ? 1 : max(1, min(2, (int) ($meta['audio_channels'] ?? 1))),
            default => $recordingChannels,
        };

        // A transcript computed on the device (Parakeet) cost the server nothing, whatever provider it names.
        $billable = self::isBillable($provider) && ($meta['source'] ?? null) !== 'device';
        $model = $meta['speech_model_used'] ?? $meta['model'] ?? null;

        return UsageLedgerEntry::query()->firstOrCreate(
            ['user_id' => $session->user_id, 'report_id' => self::finalReportId($transcript)],
            [
                'workspace_id' => $session->workspace_id,
                'device_id' => $session->device_id,
                'capture_session_id' => $session->id,
                'transcript_id' => $transcript->id,
                'source' => UsageLedgerEntry::SOURCE_SERVER,
                ...$this->pricing($provider, $useCase, max(0, $duration), $channels, $billable),
                'model' => is_string($model) ? mb_substr($model, 0, 64) : null,
                'occurred_at' => now(),
            ],
        );
    }

    /**
     * `final/{transcript id}/{provider job}` (or `a{attempt}` for providers without job ids). The `/` keeps server rows
     * out of the device report-id alphabet, so a device can never claim one; long job ids are hashed to fit 64 chars.
     */
    public static function finalReportId(Transcript $transcript): string
    {
        $job = $transcript->provider_job_id;
        $suffix = match (true) {
            $job === null || $job === '' => 'a'.$transcript->attempts,
            strlen($job) <= 30 && preg_match('/^[A-Za-z0-9._:-]+$/', $job) === 1 => $job,
            default => 'h'.substr(hash('sha256', $job), 0, 29),
        };

        return 'final/'.$transcript->id.'/'.$suffix;
    }

    /**
     * @return array{use_case: string, provider: string, duration_ms: int, channels: int, billed_ms: int,
     *     unit_price_usd: float, cost_usd: float, billable: bool}
     */
    private function pricing(SttProviderKey $provider, SttUseCase $useCase, int $durationMs, int $channels, ?bool $billable = null): array
    {
        $billable ??= self::isBillable($provider);
        $price = $billable ? $this->settings->priceFor($provider, $useCase) : 0.0;
        $billed = $durationMs * $channels;

        return [
            'use_case' => $useCase->value,
            'provider' => $provider->value,
            'duration_ms' => $durationMs,
            'channels' => $channels,
            'billed_ms' => $billed,
            'unit_price_usd' => round($price, 4),
            'cost_usd' => round($billed / self::MS_PER_HOUR * $price, 6),
            'billable' => $billable,
        ];
    }

    /** The streaming model configured when the report arrived (informational). */
    private function streamingModel(SttProviderKey $provider): ?string
    {
        $model = match ($provider) {
            SttProviderKey::AssemblyAi => $this->settings->get('assemblyai.streaming_model'),
            SttProviderKey::ElevenLabs => $this->settings->get('elevenlabs.realtime_model_id'),
            default => null,
        };

        return is_string($model) && $model !== '' ? mb_substr($model, 0, 64) : null;
    }

    /** `ended_at` decides the month: defaults to now, clamped to [now - 35 days, now + 5 minutes]. */
    private function clampedEnd(?string $endedAt): Carbon
    {
        $now = now()->utc();
        if ($endedAt === null || $endedAt === '') {
            return $now;
        }
        $at = Carbon::parse($endedAt)->utc();
        $earliest = $now->copy()->subDays(self::MAX_REPORT_AGE_DAYS);
        $latest = $now->copy()->addMinutes(self::MAX_REPORT_LEAD_MINUTES);

        return $at->lt($earliest) ? $earliest : ($at->gt($latest) ? $latest : $at);
    }
}
