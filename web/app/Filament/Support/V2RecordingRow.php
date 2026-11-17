<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
use App\Models\SessionLabel;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * One recording as the v2.0 Library draws it (the apps' RecordingRow): the source's glyph, the title, at most one
 * status (only what needs the person: Untranscribed, Transcribing, Waiting, Failed), a second line (the source and the
 * project or caller), and on the end the time above the length.
 */
final class V2RecordingRow
{
    /**
     * @return array{id: string, title: string, icon: string, source: string, line: string, time: string, full_time: string,
     *     iso: string, length: string, status: array{label: string, tone: string, icon: string}|null}
     */
    public static function for(CaptureSession $session): array
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $at = $session->started_at->copy()->setTimezone($zone);
        $labels = $session->relationLoaded('labels') ? $session->labels->map(fn (SessionLabel $l): string => $l->label)->all() : [];
        $with = $session->client->name ?? (filled($session->caller_name) ? $session->caller_name : null);
        $source = self::source($session->source_app);

        return [
            'id' => (string) $session->id,
            'title' => $session->displayTitle(),
            'icon' => self::glyph($session->source_app),
            'source' => $source,
            'line' => implode(' · ', array_filter([$source, $with, $labels[0] ?? null])),
            'time' => self::time($at, $zone),
            'full_time' => $at->locale(app()->getLocale())->translatedFormat('j F Y, '.Shell::timeFormat()),
            'iso' => $session->started_at->toIso8601ZuluString(),
            'length' => CaptureSessionsTable::duration($session->duration_ms),
            'status' => self::status($session),
        ];
    }

    /** Today's recordings show the time, older ones the day; in the console's language. */
    public static function time(Carbon $at, string $zone): string
    {
        $now = Carbon::now($zone);
        $local = $at->copy()->locale(app()->getLocale());
        if ($at->isSameDay($now)) {
            return $local->translatedFormat(Shell::timeFormat() === 'H:i' ? 'H:i' : 'g:i a');
        }

        return $local->translatedFormat($at->year === $now->year ? 'j M' : 'j M Y');
    }

    /**
     * The one status a row may carry, never "Completed".
     *
     * @return array{label: string, tone: string, icon: string}|null
     */
    public static function status(CaptureSession $session): ?array
    {
        if ($session->hasNoAudio()) {
            return ['label' => __('No audio'), 'tone' => 'neutral', 'icon' => 'alert'];
        }
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();

        return match (true) {
            $session->status === SessionStatus::Recording => ['label' => __('Waiting'), 'tone' => 'neutral', 'icon' => 'clock'],
            $session->status === SessionStatus::Uploading => ['label' => __('Uploading'), 'tone' => 'neutral', 'icon' => 'upload'],
            $session->status === SessionStatus::Failed, $final?->status === TranscriptStatus::Failed => ['label' => __('Failed'), 'tone' => 'danger', 'icon' => 'alert'],
            $session->status === SessionStatus::Processing,
            in_array($final?->status, [TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing], true) => ['label' => __('Transcribing'), 'tone' => 'neutral', 'icon' => 'clock'],
            $final === null => ['label' => __('Untranscribed'), 'tone' => 'warning', 'icon' => 'transcript'],
            default => null,
        };
    }

    /** The source's glyph: the same metaphors as the desktop and phone apps. */
    public static function glyph(SourceApp $app): string
    {
        return match ($app) {
            SourceApp::WhatsApp, SourceApp::Telegram, SourceApp::Signal, SourceApp::Messenger, SourceApp::Instagram, SourceApp::Slack, SourceApp::Discord => 'chat',
            SourceApp::WhatsAppBusiness => 'store',
            SourceApp::WhatsAppWeb => 'globe',
            SourceApp::GoogleMeet, SourceApp::Zoom, SourceApp::Skype, SourceApp::MicrosoftTeams => 'video',
            SourceApp::PhoneCall => 'phone',
            SourceApp::VoiceNote => 'mic',
            SourceApp::Other => 'dots',
        };
    }

    /** The source's name in the console's language (the apps' words). */
    public static function source(SourceApp $app): string
    {
        return __((string) $app->getLabel());
    }
}
