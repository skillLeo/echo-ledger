<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TranscriptStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case AwaitingDevice = 'awaiting_device';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitingDevice => 'Awaiting device',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            default => 'warning',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
