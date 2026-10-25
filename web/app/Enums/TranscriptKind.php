<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TranscriptKind: string implements HasLabel
{
    case Live = 'live';
    case Final = 'final';
    /** A second engine's version kept for comparison (since v1.1); `Use this version` swaps it with the final one. */
    case Alternate = 'alternate';

    public function getLabel(): string
    {
        return match ($this) {
            self::Live => 'Live captions',
            self::Final => 'Final transcript',
            self::Alternate => 'Second version',
        };
    }
}
