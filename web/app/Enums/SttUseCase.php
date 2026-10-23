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

    public function getLabel(): string
    {
        return match ($this) {
            self::LiveCaptions => 'Live captions',
            self::Dictation => 'Dictation',
            self::FinalTranscript => 'Final transcript (calls & meetings)',
            self::VoiceNote => 'Voice notes',
        };
    }

    public function isStreaming(): bool
    {
        return $this === self::LiveCaptions || $this === self::Dictation;
    }
}
