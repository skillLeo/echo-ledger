<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Ask;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Live;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;

/**
 * Where the v2.0 console's navigation goes (docs/v2/IA.md): the five modules for the sidebar and the bottom bar, and
 * the Library's level 2. A module this console can't open for the person is listed with the reason, never hidden.
 */
final class ConsoleV2Nav
{
    /** The pages that belong to Library (its level 2 included), so Library is the place you are on them. */
    public const LIBRARY_ROUTES = [
        'filament.app.resources.sessions.*', 'filament.app.resources.clients.*', 'filament.app.pages.shared-with-me',
        'filament.app.pages.shares', 'filament.app.pages.request-links', 'filament.app.pages.recently-deleted',
        'filament.app.pages.search', 'filament.app.pages.upload',
    ];

    /** The pages that belong to Settings. */
    public const SETTINGS_ROUTES = [
        'filament.app.auth.profile', 'filament.app.pages.upgrade*', 'filament.app.pages.security', 'filament.app.pages.usage',
        'filament.app.pages.workspace', 'filament.app.resources.devices.*', 'filament.app.pages.get-the-apps', 'filament.app.pages.help',
    ];

    /**
     * The five modules in order: key, label, icon, where it goes (null when it can't open here, with the reason) and
     * whether you are in it.
     *
     * @return list<array{key: string, label: string, icon: string, url: string|null, reason: string|null, active: bool}>
     */
    public static function modules(): array
    {
        $routes = request()->route()?->getName() !== null;
        $is = fn (array|string $names): bool => $routes && request()->routeIs($names);

        return [
            ['key' => 'home', 'label' => __('Home'), 'icon' => 'home', 'url' => Dashboard::getUrl(), 'reason' => null, 'active' => $is('filament.app.pages.dashboard')],
            ['key' => 'library', 'label' => __('Library'), 'icon' => 'library', 'url' => CaptureSessionResource::canAccess() ? CaptureSessionResource::getUrl('index') : null,
                'reason' => __('Your workspace shows you what is shared with you.'), 'active' => $is(self::LIBRARY_ROUTES)],
            ['key' => 'live', 'label' => __('Live'), 'icon' => 'live', 'url' => Live::canAccess() ? Live::getUrl() : null,
                'reason' => __('Recording calls and meetings is in the phone and computer apps.'), 'active' => $is(['filament.app.pages.live', 'filament.app.pages.live-assist.*'])],
            ['key' => 'ask', 'label' => __('Ask'), 'icon' => 'ask', 'url' => Ask::canAccess() ? Ask::getUrl() : null,
                'reason' => __('Ask works on a project\'s recordings, which your role in this workspace can\'t open.'), 'active' => $is('filament.app.pages.ask')],
            ['key' => 'settings', 'label' => __('Settings'), 'icon' => 'settings', 'url' => EditProfile::settingsUrl(), 'reason' => null, 'active' => $is(self::SETTINGS_ROUTES)],
        ];
    }

    /**
     * The Library's level 2: All recordings, Projects, Shared and Trash, those the person can open.
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    public static function libraryPlaces(): array
    {
        $routes = request()->route()?->getName() !== null;
        $is = fn (array|string $names): bool => $routes && request()->routeIs($names);
        $places = [];
        if (CaptureSessionResource::canAccess()) {
            $places[] = ['key' => 'all', 'label' => __('All recordings'), 'url' => CaptureSessionResource::getUrl('index'), 'active' => $is(['filament.app.resources.sessions.*', 'filament.app.pages.search', 'filament.app.pages.upload'])];
        }
        if (ClientResource::canAccess()) {
            $places[] = ['key' => 'projects', 'label' => __('Projects'), 'url' => ClientResource::getUrl('index'), 'active' => $is('filament.app.resources.clients.*')];
        }
        if (SharedWithMe::canAccess()) {
            $places[] = ['key' => 'shared', 'label' => __('Shared'), 'url' => SharedWithMe::getUrl(), 'active' => $is(['filament.app.pages.shared-with-me', 'filament.app.pages.shares', 'filament.app.pages.request-links'])];
        }
        if (RecentlyDeleted::canAccess()) {
            $places[] = ['key' => 'trash', 'label' => __('Trash'), 'url' => RecentlyDeleted::getUrl(), 'active' => $is('filament.app.pages.recently-deleted')];
        }

        return $places;
    }
}
