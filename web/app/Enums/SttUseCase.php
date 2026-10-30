<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SttUseCase: string implements HasLabel
{
    case LiveCaptions = 'live_captions';
    case Dictation = 'dictation';
    case FinalTranscript = 'final_transcript';
    case VoiceNote = 'voice_note';
    // Live Translate (since v1.3): always Soniox real-time, reported by the phone for Usage and the budget.
    case GameTranslate = 'game_translate';
    case WatchTranslate = 'watch_translate';

    public function getLabel(): string
    {
        return match ($this) {
            self::LiveCaptions => 'Live captions',
            self::Dictation => 'Dictation',
            self::FinalTranscript => 'Final transcript (calls & meetings)',
            self::VoiceNote => 'Voice notes',
            self::GameTranslate => 'Game translate',
            self::WatchTranslate => 'Watch translate',
        };
    }

    /** Streaming use cases whose engine is chosen in Settings (Live Translate has no choice: always Soniox). */
    public function isStreaming(): bool
    {
        return $this === self::LiveCaptions || $this === self::Dictation;
    }

    public function isLiveTranslate(): bool
    {
        return $this === self::GameTranslate || $this === self::WatchTranslate;
    }
}
