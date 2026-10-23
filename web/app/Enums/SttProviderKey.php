<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SttProviderKey: string implements HasLabel
{
    case AssemblyAi = 'assemblyai';
    case ElevenLabs = 'elevenlabs';
    case AzureMai = 'azure_mai';
    case WhisperCppLocal = 'whispercpp_local';
    case ParakeetLocal = 'parakeet_local';

    public function getLabel(): string
    {
        return match ($this) {
            self::AssemblyAi => 'AssemblyAI',
            self::ElevenLabs => 'ElevenLabs Scribe',
            self::AzureMai => 'Azure Speech (MAI-Transcribe)',
            self::WhisperCppLocal => 'whisper.cpp (self-hosted)',
            self::ParakeetLocal => 'Parakeet (on-device)',
        };
    }
}
