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
    /** Since v1.6: the plan leaves transcription on EchoLedger out and the person has no key of their own. */
    case NeedsTranscriptionKey = 'needs_transcription_key';
    /** Since v1.8.1: Groq refused the person's own key (401/403); another try with the same key cannot work. */
    case OwnKeyRejected = 'own_key_rejected';

    /** Longest provider reason quoted in a `provider_rejected` message. */
    public const MAX_REASON_CHARS = 200;

    /**
     * The message kept with a failed transcript, for the operator and the logs; `provider_rejected` and
     * `provider_not_configured` add their own detail. People read plainWords() instead.
     */
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
            self::NeedsTranscriptionKey => 'Add your free Groq key in Account → Transcription key',
            self::OwnKeyRejected => "Groq did not accept the person's own key.",
        };
    }

    /**
     * Why a transcript failed, in plain words: the one set of sentences people read everywhere (since v1.8.1, D-129 and
     * D-131), on the console's recording page and Library, in Activity and the bell, and as the API's `error` (so an app
     * that shows `error` says the same). Never the stored `error`, which may quote the engine or its provider: that stays
     * in the transcript's `error` column, for the operator and the logs. A missing or unknown code reads as an engine
     * problem. The Free plan's own Groq key is the one name people must act on.
     */
    public static function plainWords(?self $code): string
    {
        return match ($code) {
            self::NoAudio => 'Nothing could be heard in this recording.',
            self::BudgetExceeded => 'The monthly transcription limit was reached.',
            self::RecordingMissing => 'The audio did not fully reach the server, or it is no longer stored.',
            self::ProviderNotConfigured => 'No speech engine is set up for this language yet.',
            self::NeedsTranscriptionKey => 'Add your free Groq key in Account → Transcription key.',
            self::OwnKeyRejected => 'Groq did not accept your key. Check it in Account → Transcription key, then try again.',
            self::Interrupted => 'Transcription was interrupted. Your audio is safe: try again.',
            default => 'The speech engines could not transcribe it this time. Your audio is safe: try again.',
        };
    }

    /**
     * Whether another engine may succeed where this one failed (since v1.3 the next engine in the routing table is
     * tried): a provider problem, not the recording's (no audio, missing) or the limit's.
     */
    public function retryable(): bool
    {
        return in_array($this, [self::ProviderRejected, self::ProviderUnreachable, self::ProviderTimeout, self::ProviderNotConfigured, self::Interrupted], true);
    }

    /**
     * A provider's refusal that means the recording holds no speech at all ("language_detection cannot be performed on
     * files with no spoken audio", "no speech detected", "audio file is empty"): that is `no_audio`, not the provider's
     * fault, and no other engine can do better.
     */
    public static function saysNoSpeech(string $reason): bool
    {
        return preg_match('/no (spoken )?(audio|speech)\b(?! codec)|no speech (was )?(found|detected)|contains no (audio|speech)|audio (file )?(is|was) (empty|silent)|empty audio|silent audio/i', $reason) === 1;
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
