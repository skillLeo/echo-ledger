<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\SessionLabel;
use App\Services\Library\LibraryFilters;
use App\Services\RetentionPolicy;
use App\Services\SettingsRepository;
use App\Services\Views\TranscriptViews;
use Illuminate\Support\Carbon;

/**
 * One recording as the v2.0 Library draws it (the apps' RecordingRow): the source's glyph, the title, at most one
 * status (only what needs the person: Untranscribed, Transcribing, Waiting, Failed), a second line (the source and the
 * project or caller), and on the end the time above the length. Since U9 (contract 2.0.0-i, parity plan 3) also the
 * row's indicators: the unread dot, the important flag, its labels' colour dots, "Audio · N days left" and a snippet
 * of what was said.
 */
final class V2RecordingRow
{
    /**
     * The eight label colours as the server stores them (#rrggbb, docs/v2/DESIGN_SYSTEM.md "Label colours",
     * tokens/global/label.json); a row draws one with its role token var(--el-role-label-<name>-dot), never this value.
     */
    public const LABEL_COLOURS = [
        'grey' => '#8e8a80', 'red' => '#e15f56', 'orange' => '#dc6718', 'amber' => '#b3820f',
        'green' => '#2d9a63', 'teal' => '#26978f', 'blue' => '#458cde', 'pink' => '#d7609c',
    ];

    /** At most this many label dots on a row (the rest are in its labels). */
    public const MAX_LABEL_DOTS = 3;

    /**
     * @return array{id: string, title: string, icon: string, source: string, line: string, device: string|null, pinned: bool,
     *     time: string, full_time: string, iso: string, app: string, device_kind: string|null, source_words: string, quick_source: string, quick_source_words: string, length: string, status: array{label: string, tone: string, icon: string}|null,
     *     important: bool, unread: bool, snippet: string|null, labels: list<array{name: string, class: string, own: string|null}>, left: array{text: string, days: int, soon: bool}|null,
     *     converting: array{state: string, view: string, label: string, percent: int|null}|null}
     */
    public static function for(CaptureSession $session): array
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $at = $session->started_at->copy()->setTimezone($zone);
        $labels = $session->relationLoaded('labels') ? $session->labels->map(fn (SessionLabel $l): string => $l->label)->all() : [];
        $with = $session->client->name ?? (filled($session->caller_name) ? $session->caller_name : null);
        $source = self::source($session->source_app);
        // The device it came from, as the classic row says it ("WhatsApp · Galaxy S25"); a web upload says so.
        $device = $session->device !== null ? $session->device->name : ($session->source_device === SourceDevice::Web ? __('Web upload') : null);
        $status = self::status($session);

        return [
            'id' => (string) $session->id,
            'title' => $session->displayTitle(),
            'icon' => self::glyph($session->source_app),
            // Since U9b (WEB-LIB-020): the app's own mark with the device it came from, named as the desktop names it.
            'app' => $session->source_app->value,
            'device_kind' => self::deviceKind($session->source_device),
            'source_words' => self::sourceWords($session->source_app, $session->source_device),
            // Since 2.0.0-p: the Source a click on the mark filters by (LibraryFilters::source), and its name.
            'quick_source' => $quick = LibraryFilters::source($session->source_app->value, $session->origin),
            'quick_source_words' => LibraryFilterBar::optionLabel('source', $quick),
            'source' => $source,
            'line' => implode(' · ', array_filter([$source, $device, $with, $labels[0] ?? null])),
            'device' => $device,
            'pinned' => (bool) $session->pinned,
            'time' => self::time($at, $zone),
            'full_time' => $at->locale(app()->getLocale())->translatedFormat('j F Y, '.Shell::timeFormat()),
            'iso' => $session->started_at->toIso8601ZuluString(),
            'length' => CaptureSessionsTable::duration($session->duration_ms),
            'status' => $status,
            // Since U19: "Converting to Roman Urdu… 60%" with a small spinner while the view it opens in is made (from the
            // moment the transcript is there), or "Couldn't convert to Roman Urdu"; only when no other status shows.
            'converting' => $status === null ? RecordingProgress::conversion($session) : null,
            'important' => (bool) $session->important,
            // The unread dot: this person has not opened it yet (only known when the list loaded their opens).
            'unread' => $session->relationLoaded('opens') && $session->opens->isEmpty(),
            'snippet' => self::snippet($session),
            'labels' => self::labelDots($session, $labels),
            'left' => self::daysLeft($session),
        ];
    }

    /** The first words of the finished transcript, as the apps' row shows them; null without one. */
    public static function snippet(CaptureSession $session): ?string
    {
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : null;

        // Since U19 in the view it opens in once made, where the row has the views (the API's preview_view).
        return $final?->status === TranscriptStatus::Completed && filled($final->text) ? (TranscriptViews::rowPreview($final, 140)['text'] ?? $final->preview(140)) : null;
    }

    /** "Audio · N days left" shows from this many days left (EL-Design: not a badge on every row). */
    public const DAYS_LEFT_FROM = 7;

    /** …and in the warning colour from this many days left down. */
    public const DAYS_LEFT_WARNING = 3;

    /**
     * "Audio · N days left" when retention moves the recording to Trash within a week: N is the hours left divided by
     * 24, rounded up, never below 0 (contract 2.0.0-i expires_at), in the warning colour from 3 days down; null when it
     * has longer, is kept forever or still recording. The full date stays in the recording's details.
     *
     * @return array{text: string, days: int, soon: bool}|null
     */
    public static function daysLeft(CaptureSession $session): ?array
    {
        $at = app(RetentionPolicy::class)->expiresAt($session);
        if ($at === null) {
            return null;
        }
        $days = max(0, (int) ceil(Carbon::now()->diffInSeconds($at, false) / 3600 / 24));
        if ($days > self::DAYS_LEFT_FROM) {
            return null;
        }

        return ['text' => trans_choice('Audio · :count day left|Audio · :count days left', $days, ['count' => $days]), 'days' => $days, 'soon' => $days <= self::DAYS_LEFT_WARNING];
    }

    /**
     * Each label's dot (at most three), by the design's label colour rules (EL-Design's .v2-label-dot classes): one of
     * the eight takes its class; any other stored #rrggbb draws only as the dot, through --label-own, with a hairline
     * ring; no colour, or a value that isn't #rrggbb, the neutral ringed dot. The stored value is never text or fill.
     *
     * @param  list<string>  $labels
     * @return list<array{name: string, class: string, own: string|null}>
     */
    public static function labelDots(CaptureSession $session, array $labels): array
    {
        if ($labels === []) {
            return [];
        }
        $colours = array_change_key_case(LibraryItemResource::labelColors($session), CASE_LOWER);
        $dots = [];
        foreach (array_slice($labels, 0, self::MAX_LABEL_DOTS) as $label) {
            $dots[] = ['name' => $label, ...self::dotLook($colours[mb_strtolower($label)] ?? null)];
        }

        return $dots;
    }

    /**
     * A stored colour's dot: "v2-label-dot--<name>" for one of the eight (matched ignoring case), else
     * "v2-label-dot--other" with the colour as --label-own when it is a valid #rrggbb, or none.
     *
     * @return array{class: string, own: string|null}
     */
    public static function dotLook(?string $stored): array
    {
        $value = strtolower(trim((string) $stored));
        $name = array_search($value, self::LABEL_COLOURS, true);
        if (is_string($name)) {
            return ['class' => 'v2-label-dot--'.$name, 'own' => null];
        }

        return ['class' => 'v2-label-dot--other', 'own' => preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null];
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

    /** The device badge of a source mark (filament.v2.components.source-logo): phone, computer or web. */
    public static function deviceKind(?SourceDevice $device): ?string
    {
        return match ($device) {
            SourceDevice::Phone => 'phone',
            SourceDevice::Desktop => 'computer',
            SourceDevice::Web => 'web',
            default => null,
        };
    }

    /** "WhatsApp, on a phone": what the mark stands for, in the console's language (desktop sourceWords). */
    public static function sourceWords(SourceApp $app, ?SourceDevice $device): string
    {
        $name = self::source($app);

        return match ($device) {
            SourceDevice::Phone => __(':app, on a phone', ['app' => $name]),
            SourceDevice::Desktop => __(':app, on a computer', ['app' => $name]),
            SourceDevice::Web => __(':app, on the web', ['app' => $name]),
            default => $name,
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
