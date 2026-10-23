<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TranscriptKind: string implements HasLabel
{
    case Live = 'live';
    case Final = 'final';

    public function getLabel(): string
    {
        return match ($this) {
            self::Live => 'Live captions',
            self::Final => 'Final transcript',
        };
    }
}
