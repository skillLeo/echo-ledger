<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum SourceApp: string implements HasColor, HasIcon, HasLabel
{
    case WhatsApp = 'whatsapp';
    case WhatsAppBusiness = 'whatsapp_business';
    case GoogleMeet = 'google_meet';
    case Zoom = 'zoom';
    case VoiceNote = 'voice_note';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::WhatsAppBusiness => 'WhatsApp Business',
            self::GoogleMeet => 'Google Meet',
            self::Zoom => 'Zoom',
            self::VoiceNote => 'Voice note',
            self::Other => 'Other',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::WhatsApp => Heroicon::OutlinedChatBubbleLeftRight,
            self::WhatsAppBusiness => Heroicon::OutlinedBuildingStorefront,
            self::GoogleMeet, self::Zoom => Heroicon::OutlinedVideoCamera,
            self::VoiceNote => Heroicon::OutlinedMicrophone,
            self::Other => Heroicon::OutlinedEllipsisHorizontalCircle,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WhatsApp, self::WhatsAppBusiness => 'success',
            self::GoogleMeet, self::Zoom => 'info',
            self::VoiceNote => 'warning',
            self::Other => 'gray',
        };
    }
}
