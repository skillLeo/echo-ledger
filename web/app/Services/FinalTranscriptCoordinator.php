<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Starts the final transcript once every declared artifact of a session is assembled and verified.
 */
final class FinalTranscriptCoordinator
{
    /** A pending transcript is queued again at most once in this many minutes. */
    public const REQUEUE_WINDOW_MINUTES = 10;

    public function __construct(private readonly SettingsRepository $settings) {}

    public static function useCaseFor(CaptureSession $session): SttUseCase
    {
        return $session->source_app === SourceApp::VoiceNote ? SttUseCase::VoiceNote : SttUseCase::FinalTranscript;
    }

    /** Provider chosen when the session was created (kept stable even if the admin changes settings later). */
    public function providerFor(CaptureSession $session): SttProviderKey
    {
        return SttProviderKey::tryFrom((string) $session->final_provider)
            ?? $this->settings->providerFor(self::useCaseFor($session));
    }

    public function maybeStart(CaptureSession $session, bool $sync = false): ?Transcript
    {
        $recordings = $session->recordings()->get();
        $primary = $recordings->first(fn ($r) => $r->role === RecordingRole::Primary);
        if ($primary === null || $recordings->contains(fn ($r) => $r->upload_status !== UploadStatus::Ready)) {
            return null;
        }

        $existing = $session->finalTranscript()->first();
        if ($existing !== null && $existing->status !== TranscriptStatus::Failed) {
            // Still pending with no provider job: an inline attempt gave it back (or its job was lost). Queue it again,
            // at most once per window so repeated `complete` calls do not pile up jobs (each run is a no-op anyway).
            if ($existing->status === TranscriptStatus::Pending && $existing->provider_job_id === null
                && Cache::add(self::queuedKey($existing), true, now()->addMinutes(self::REQUEUE_WINDOW_MINUTES))) {
                TranscribeFinalJob::dispatch($existing->id);
            }

            return $existing; // already started (idempotent)
        }

        return $this->start($session, $existing, $sync);
    }

    /** Cache key marking that a queued run of this transcript is outstanding. */
    private static function queuedKey(Transcript $transcript): string
    {
        return 'final-transcript-queued:'.$transcript->id;
    }

    /** (Re)starts transcription, e.g. from the admin "Retry" action. */
    public function start(CaptureSession $session, ?Transcript $existing = null, bool $sync = false): Transcript
    {
        $provider = $this->providerFor($session);
        // A retry starts a new provider job: the copy kept by the provider for the previous attempt is deleted.
        $oldJob = $existing?->provider_job_id;
        $oldProvider = $existing?->providerKey();
        if ($oldJob !== null && $oldJob !== '' && $oldProvider !== null && $existing->provider_deleted_at === null) {
            app(ProviderCopyEraser::class)->schedule($oldProvider, $oldJob, $existing->provider_transcript_id);
        }
        $attributes = [
            'provider' => $provider->value,
            'status' => TranscriptStatus::Pending,
            'provider_job_id' => null,
            'provider_transcript_id' => null,
            'provider_deleted_at' => null,
            'error' => null,
            'error_code' => null,
            'attempts' => 0,
            'language' => $session->language,
        ];

        $transcript = $existing ?? $session->transcripts()->firstOrNew(['kind' => TranscriptKind::Final]);
        $transcript->fill($attributes)->save();

        $session->update(['status' => SessionStatus::Processing, 'final_provider' => $provider->value]);
        // Submit to the provider immediately on the fast path, so the transcript is not waiting for the next
        // scheduled queue run; otherwise queue it (large recordings, or when assembly ran on the queue).
        // An inline attempt that gives the transcript back (still pending) is queued again by maybeStart().
        if ($sync) {
            Cache::forget(self::queuedKey($transcript));
            Bus::dispatchNow(new TranscribeFinalJob($transcript->id));
        } else {
            Cache::put(self::queuedKey($transcript), true, now()->addMinutes(self::REQUEUE_WINDOW_MINUTES));
            TranscribeFinalJob::dispatch($transcript->id);
        }

        return $transcript;
    }
}
