<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceDevice;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Services\ChunkedUploadService;

/**
 * "Failed uploads (N)" in plain words (since v1.6, P5.3): for each recording of that smart view, what went wrong, what
 * happens next, and which button helps: Retry (transcribe it again, or pick the file again for an upload from this
 * browser) or only Delete when nothing here can fix it. Never the provider's raw error.
 */
final class FailedUpload
{
    /** Retry transcribes the recording again (its audio is on the server). */
    public const RETRY_TRANSCRIBE = 'transcribe';

    /** Retry opens the file picker: an upload from this browser carries on where it stopped. */
    public const RETRY_UPLOAD = 'upload';

    /**
     * Why [$session] is in Failed uploads, or null when it is not.
     *
     * @return array{title: string, detail: string, retry: string|null}|null
     */
    public static function for(CaptureSession $session): ?array
    {
        $recordings = $session->relationLoaded('recordings') ? $session->recordings : $session->recordings()->get();
        $primary = $recordings->first(fn (Recording $r): bool => $r->role === RecordingRole::Primary);
        $uploadFailed = $recordings->contains(fn (Recording $r): bool => $r->upload_status === UploadStatus::Failed);
        if ($uploadFailed && $primary?->isReady() !== true) {
            return self::upload($session, $recordings->first(fn (Recording $r): bool => $r->upload_status === UploadStatus::Failed));
        }
        if ($session->status !== SessionStatus::Failed) {
            return null;
        }
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();
        if ($primary?->isReady() !== true) {
            return self::upload($session, $primary);
        }
        $code = $final?->status === TranscriptStatus::Failed ? TranscriptErrorCode::tryFrom((string) $final->error_code) : null;

        return match ($code) {
            TranscriptErrorCode::NoAudio => ['title' => 'No sound was recorded',
                'detail' => 'Nothing could be heard in it, so there is nothing to transcribe. Check the microphone or call capture on the device that recorded it.', 'retry' => null],
            TranscriptErrorCode::NeedsTranscriptionKey => ['title' => 'Your plan transcribes with your own key',
                'detail' => 'Add your free Groq key in Account → Transcription key, then press Retry.', 'retry' => self::RETRY_TRANSCRIBE],
            TranscriptErrorCode::BudgetExceeded => ['title' => 'The monthly transcription limit was reached',
                'detail' => 'Press Retry once the limit is raised or the new month starts.', 'retry' => self::RETRY_TRANSCRIBE],
            TranscriptErrorCode::RecordingMissing => ['title' => 'The audio is missing',
                'detail' => 'The recording did not fully reach the server. The device that recorded it sends it again if it still has it; otherwise delete it.', 'retry' => null],
            TranscriptErrorCode::ProviderNotConfigured => ['title' => 'Transcription is not set up',
                'detail' => 'No speech engine is set up for this language yet. Press Retry once one is.', 'retry' => self::RETRY_TRANSCRIBE],
            default => ['title' => 'The transcript could not be made',
                'detail' => 'The speech engines could not transcribe it this time (they may have been busy or down). Your audio is safe: press Retry.', 'retry' => self::RETRY_TRANSCRIBE],
        };
    }

    /** @return array{title: string, detail: string, retry: string|null} */
    private static function upload(CaptureSession $session, ?Recording $recording): array
    {
        $damaged = $recording?->error === ChunkedUploadService::VERIFICATION_FAILED;
        if ($session->source_device === SourceDevice::Web) {
            return ['title' => $damaged ? 'The file arrived damaged' : 'The upload did not finish',
                'detail' => 'Press Retry and choose the same file again: the upload carries on where it stopped.', 'retry' => self::RETRY_UPLOAD];
        }
        $device = CaptureSessionsTable::deviceLabel($session);

        return ['title' => $damaged ? 'The file arrived damaged' : 'The upload did not finish',
            'detail' => "{$device} sends it again by itself the next time EchoLedger is open there and online. Nothing to do here unless you want to delete it.",
            'retry' => null];
    }
}
