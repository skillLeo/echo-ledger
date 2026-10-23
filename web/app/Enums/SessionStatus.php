<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum SessionStatus: string implements HasColor, HasIcon, HasLabel
{
    case Recording = 'recording';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Recording => Heroicon::OutlinedMicrophone,
            self::Uploading => Heroicon::OutlinedCloudArrowUp,
            self::Processing => Heroicon::OutlinedArrowPath,
            self::Completed => Heroicon::OutlinedCheckCircle,
            self::Failed => Heroicon::OutlinedExclamationTriangle,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Recording => 'danger',
            self::Uploading, self::Processing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
