<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a transcript failed (docs/API.md transcript `error_code` and its message table). Messages are platform-neutral:
 * each app adds its own hint for `no_audio`.
 */
enum TranscriptErrorCode: string
{
    case NoAudio = 'no_audio';
    case BudgetExceeded = 'budget_exceeded';
    case ProviderUnreachable = 'provider_unreachable';
    case ProviderRejected = 'provider_rejected';
    case ProviderNotConfigured = 'provider_not_configured';
    case ProviderTimeout = 'provider_timeout';
    case Interrupted = 'interrupted';
    case RecordingMissing = 'recording_missing';

    /** Longest provider reason quoted in a `provider_rejected` message. */
    public const MAX_REASON_CHARS = 200;

    /** The documented message; `provider_rejected` and `provider_not_configured` add their own detail. */
    public function message(): string
    {
        return match ($this) {
            self::NoAudio => 'No audio was captured in this recording.',
            self::BudgetExceeded => 'Monthly transcription limit reached',
            self::ProviderUnreachable => 'The speech provider could not be reached. Try again later.',
            self::ProviderRejected => 'The speech provider could not transcribe this recording.',
            self::ProviderNotConfigured => 'The speech provider is not configured.',
            self::ProviderTimeout => 'The speech provider did not finish within 6 hours.',
            self::Interrupted => 'Transcription was interrupted. Retry it from the console.',
            self::RecordingMissing => 'The recording is missing or not ready.',
        };
    }

    /**
     * `The speech provider could not transcribe this recording: <reason>`, quoting at most 200 characters of the
     * provider's reason with links and key-like strings removed (a reason may echo a request URL or header).
     */
    public static function rejectedMessage(string $reason): string
    {
        $clean = (string) preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '[link]', $reason);
        $clean = (string) preg_replace('/[A-Za-z0-9_\-]{32,}/', '[hidden]', $clean);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
        if ($clean === '') {
            return self::ProviderRejected->message();
        }

        return 'The speech provider could not transcribe this recording: '.mb_substr($clean, 0, self::MAX_REASON_CHARS);
    }
}
