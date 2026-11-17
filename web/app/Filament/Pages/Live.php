<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\LiveAssist\AssistPreps;
use App\Filament\Pages\LiveAssist\AssistSessions;
use App\Filament\Support\ConsoleV2;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Live (v2.0, docs/v2/IA.md 3): everything during a call. On the web that is a voice note recorded in the browser and,
 * where it is on, Live Assist's sessions and preps; recording calls and meetings, Live Translate, Dictation and
 * Captions are in the apps, so they are listed with where to find them, never hidden. Only under ui_v2.shell.
 */
class Live extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'live';

    protected string $view = 'filament.v2.live';

    public static function canAccess(): bool
    {
        return ConsoleV2::shell();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Live');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $record = Upload::canAccess() ? Upload::getUrl(['record' => 1]) : null;
        $apps = GetTheApps::getUrl();

        return [
            'sections' => [
                [
                    'title' => __('Record'),
                    'rows' => [
                        ['icon' => 'mic', 'title' => __('Voice note'), 'help' => __('Record yourself now, in this browser'), 'url' => $record,
                            'reason' => __('Your role in this workspace can\'t add recordings.')],
                        ['icon' => 'phone', 'title' => __('Record calls and meetings'), 'help' => __('WhatsApp and phone calls on Android; Meet, Zoom and Teams on a computer'),
                            'url' => null, 'reason' => __('In the phone and computer apps'), 'link' => $apps, 'linkLabel' => __('Get the apps')],
                    ],
                ],
                ...(AssistSessions::canAccess() ? [[
                    'title' => __('During a call'),
                    'rows' => [
                        ['icon' => 'sparkle', 'title' => __('Live Assist sessions'), 'help' => __('Past sessions, reports and practice'), 'url' => AssistSessions::getUrl(), 'reason' => null],
                        ['icon' => 'file', 'title' => __('Preps'), 'help' => __('Get ready for a meeting or an interview'), 'url' => AssistPreps::canAccess() ? AssistPreps::getUrl() : null, 'reason' => null],
                    ],
                ]] : []),
                [
                    'title' => __('Tools'),
                    'rows' => [
                        ['icon' => 'globe', 'title' => __('Live Translate'), 'help' => __('Games and videos, translated on screen'), 'url' => null, 'reason' => __('In the Android app')],
                        ['icon' => 'mic', 'title' => __('Dictation'), 'help' => __('Speak instead of typing, in any app'), 'url' => null, 'reason' => __('In the phone and computer apps')],
                        ['icon' => 'captions', 'title' => __('Captions'), 'help' => __('Live words of a call on screen'), 'url' => null, 'reason' => __('In the phone and computer apps')],
                    ],
                ],
            ],
        ];
    }
}
