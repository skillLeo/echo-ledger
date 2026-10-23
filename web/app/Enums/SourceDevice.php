<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum SourceDevice: string implements HasColor, HasIcon, HasLabel
{
    case Phone = 'phone';
    case Desktop = 'desktop';
    case Web = 'web';

    public function getLabel(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Desktop => 'Desktop',
            self::Web => 'Web',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Phone => Heroicon::OutlinedDevicePhoneMobile,
            self::Desktop => Heroicon::OutlinedComputerDesktop,
            self::Web => Heroicon::OutlinedGlobeAlt,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Phone => 'success',
            self::Desktop => 'info',
            self::Web => 'gray',
        };
    }

    /** Icon key shared with the device apps (docs/API.md). */
    public function iconKey(): string
    {
        return match ($this) {
            self::Phone => 'device-phone-mobile',
            self::Desktop => 'computer-desktop',
            self::Web => 'globe-alt',
        };
    }
}
