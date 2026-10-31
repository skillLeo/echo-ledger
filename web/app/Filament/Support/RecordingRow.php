<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
use App\Models\SessionLabel;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * What one recording row shows, the same in the Library, a client's Recordings and the detail beside the list: the
 * app tile, the title, the time, a second line (length · client · label) and a status only when it needs the person.
 */
final class RecordingRow
{
    /**
     * @return array{id: string, title: string, app: string, device: string, tile: string, icon: string, device_icon: string,
     *     time: string, full_time: string, iso: string, meta: string, status: array{label: string, tone: string, icon: string, spin: bool}|null}
     */
    public static function for(CaptureSession $session): array
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $at = $session->started_at->copy()->setTimezone($zone);
        $labels = $session->relationLoaded('labels') ? $session->labels->map(fn (SessionLabel $l): string => $l->label)->all() : [];
        $with = $session->client->name ?? ($session->caller_name !== null && $session->caller_name !== '' ? $session->caller_name : null);

        return [
            'id' => (string) $session->id,
            'title' => $session->displayTitle(),
            'app' => $session->source_app->getLabel(),
            'device' => CaptureSessionsTable::deviceLabel($session),
            'tile' => self::tile($session->source_app),
            'icon' => self::appIcon($session->source_app),
            'device_icon' => self::deviceIcon($session->source_device),
            'time' => self::shortTime($at, $zone),
            'full_time' => $at->format('M j, Y · g:i A'),
            'iso' => $session->started_at->toIso8601ZuluString(),
            'meta' => implode(' · ', array_filter([CaptureSessionsTable::duration($session->duration_ms), $with, $labels[0] ?? null])),
            'status' => self::status($session),
        ];
    }

    /** Today's recordings show the time; older ones the day, with the year when it is not this year. */
    public static function shortTime(Carbon $at, string $zone): string
    {
        $now = Carbon::now($zone);
        if ($at->isSameDay($now)) {
            return $at->format('g:i A');
        }

        return $at->year === $now->year ? $at->format('M j') : $at->format('M j, Y');
    }

    /**
     * The one status a row may carry: never "Completed", only what needs the person or is still moving.
     *
     * @return array{label: string, tone: string, icon: string, spin: bool}|null
     */
    public static function status(CaptureSession $session): ?array
    {
        if ($session->hasNoAudio()) {
            return ['label' => 'No audio', 'tone' => 'neutral', 'icon' => 'heroicon-o-speaker-x-mark', 'spin' => false];
        }
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();

        return match (true) {
            $session->status === SessionStatus::Recording => ['label' => 'Waiting', 'tone' => 'brand', 'icon' => 'heroicon-o-clock', 'spin' => false],
            $session->status === SessionStatus::Uploading => ['label' => 'Uploading', 'tone' => 'brand', 'icon' => 'heroicon-o-arrow-path', 'spin' => CaptureSessionsTable::isPending($session)],
            $session->status === SessionStatus::Failed, $final?->status === TranscriptStatus::Failed => ['label' => 'Failed', 'tone' => 'danger', 'icon' => 'heroicon-o-exclamation-circle', 'spin' => false],
            $session->status === SessionStatus::Processing,
            in_array($final?->status, [TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing], true) => ['label' => 'Transcribing', 'tone' => 'brand', 'icon' => 'heroicon-o-arrow-path', 'spin' => CaptureSessionsTable::isPending($session)],
            default => null,
        };
    }

    /** The app's colour family for its tile (the phone draws the installed app's icon on a 12 % tint). */
    public static function tile(SourceApp $app): string
    {
        return match ($app) {
            SourceApp::WhatsApp, SourceApp::WhatsAppBusiness, SourceApp::WhatsAppWeb, SourceApp::Signal => 'green',
            SourceApp::GoogleMeet, SourceApp::Zoom => 'teal',
            SourceApp::MicrosoftTeams, SourceApp::Slack, SourceApp::Discord, SourceApp::Messenger, SourceApp::Instagram => 'violet',
            SourceApp::Telegram, SourceApp::Skype, SourceApp::VoiceNote, SourceApp::PhoneCall => 'indigo',
            SourceApp::Other => 'slate',
        };
    }

    /** Filled icons on the tile, as the phone's rows. */
    public static function appIcon(SourceApp $app): string
    {
        return match ($app) {
            SourceApp::WhatsApp, SourceApp::WhatsAppWeb, SourceApp::Telegram, SourceApp::Signal, SourceApp::Messenger, SourceApp::Instagram => 'heroicon-s-chat-bubble-left-right',
            SourceApp::WhatsAppBusiness => 'heroicon-s-building-storefront',
            SourceApp::GoogleMeet, SourceApp::Zoom, SourceApp::Skype => 'heroicon-s-video-camera',
            SourceApp::MicrosoftTeams, SourceApp::Slack, SourceApp::Discord => 'heroicon-s-user-group',
            SourceApp::PhoneCall => 'heroicon-s-phone',
            SourceApp::VoiceNote => 'heroicon-s-microphone',
            SourceApp::Other => 'heroicon-s-musical-note',
        };
    }

    public static function deviceIcon(SourceDevice $device): string
    {
        return match ($device) {
            SourceDevice::Phone => 'heroicon-s-device-phone-mobile',
            SourceDevice::Desktop => 'heroicon-s-computer-desktop',
            SourceDevice::Web => 'heroicon-s-globe-alt',
        };
    }
}
