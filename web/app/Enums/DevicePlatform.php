<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DevicePlatform: string implements HasLabel
{
    case Android = 'android';
    case Ios = 'ios';
    case Windows = 'windows';
    case MacOs = 'macos';
    case Linux = 'linux';

    public function getLabel(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
            self::Windows => 'Windows',
            self::MacOs => 'macOS',
            self::Linux => 'Linux',
        };
    }

    /** The server derives the library label from the platform; clients never send it. */
    public function sourceDevice(): SourceDevice
    {
        return match ($this) {
            self::Android, self::Ios => SourceDevice::Phone,
            self::Windows, self::MacOs, self::Linux => SourceDevice::Desktop,
        };
    }
}
