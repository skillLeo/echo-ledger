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
    case WhatsAppWeb = 'whatsapp_web';
    case GoogleMeet = 'google_meet';
    case Zoom = 'zoom';
    case MicrosoftTeams = 'microsoft_teams';
    // Since v1.3: the phone records calls in every app of its call-app list.
    case Telegram = 'telegram';
    case Signal = 'signal';
    case Messenger = 'messenger';
    case Instagram = 'instagram';
    case Skype = 'skype';
    case Slack = 'slack';
    case Discord = 'discord';
    case PhoneCall = 'phone_call';
    case VoiceNote = 'voice_note';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::WhatsAppBusiness => 'WhatsApp Business',
            self::WhatsAppWeb => 'WhatsApp Web',
            self::GoogleMeet => 'Google Meet',
            self::Zoom => 'Zoom',
            self::MicrosoftTeams => 'Microsoft Teams',
            self::Telegram => 'Telegram',
            self::Signal => 'Signal',
            self::Messenger => 'Messenger',
            self::Instagram => 'Instagram',
            self::Skype => 'Skype',
            self::Slack => 'Slack',
            self::Discord => 'Discord',
            self::PhoneCall => 'Phone call',
            self::VoiceNote => 'Voice note',
            self::Other => 'Other',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::WhatsApp => Heroicon::OutlinedChatBubbleLeftRight,
            self::WhatsAppBusiness => Heroicon::OutlinedBuildingStorefront,
            self::WhatsAppWeb => Heroicon::OutlinedGlobeAlt,
            self::GoogleMeet, self::Zoom, self::Skype => Heroicon::OutlinedVideoCamera,
            self::MicrosoftTeams, self::Slack, self::Discord => Heroicon::OutlinedUserGroup,
            self::Telegram, self::Signal, self::Messenger, self::Instagram => Heroicon::OutlinedChatBubbleLeftRight,
            self::PhoneCall => Heroicon::OutlinedPhone,
            self::VoiceNote => Heroicon::OutlinedMicrophone,
            self::Other => Heroicon::OutlinedEllipsisHorizontalCircle,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WhatsApp, self::WhatsAppBusiness, self::WhatsAppWeb => 'success',
            self::GoogleMeet, self::Zoom, self::MicrosoftTeams, self::Skype => 'info',
            self::Telegram, self::Signal, self::Messenger, self::Instagram, self::Slack, self::Discord => 'info',
            self::PhoneCall => 'primary',
            self::VoiceNote => 'warning',
            self::Other => 'gray',
        };
    }
}
