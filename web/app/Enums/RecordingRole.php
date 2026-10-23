<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RecordingRole: string implements HasLabel
{
    case Primary = 'primary';
    case Channel1 = 'channel_1';
    case Channel2 = 'channel_2';

    public function getLabel(): string
    {
        return match ($this) {
            self::Primary => 'Primary (playback)',
            self::Channel1 => 'Channel 1 · Me',
            self::Channel2 => 'Channel 2 · Client',
        };
    }
}
