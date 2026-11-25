<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Activity;
use App\Filament\Pages\Ask;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Live;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Shares;
use App\Filament\Pages\Upload;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Services\Workspaces\ConsoleAccess;

/**
 * Where the v2.0 console's navigation goes (docs/v2/IA.md, docs/PLAN_V2_PARITY.md section 2): Upload recording at the
 * top, the modules for the sidebar and the bottom bar, the Library's level 2, the projects, and Activity at the foot. A module this console can't open for the person is listed with the reason, never hidden.
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
     * The modules in order (parity plan 2): Home, Library, Projects, Live, Ask, then Settings (at the sidebar's foot):
     * key, label, icon, where it goes (null when it can't open here, with the reason) and whether you are in it.
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
                'reason' => __('Your workspace shows you what is shared with you.'), 'active' => $is(array_values(array_diff(self::LIBRARY_ROUTES, ['filament.app.resources.clients.*'])))],
            ['key' => 'projects', 'label' => __('Projects'), 'icon' => 'folder', 'url' => ClientResource::canAccess() ? ClientResource::getUrl('index') : null,
                'reason' => __('Your role in this workspace can\'t open projects.'), 'active' => $is('filament.app.resources.clients.*')],
            ['key' => 'live', 'label' => __('Live'), 'icon' => 'live', 'url' => Live::canAccess() ? Live::getUrl() : null,
                'reason' => __('Recording calls and meetings is in the phone and computer apps.'), 'active' => $is(['filament.app.pages.live', 'filament.app.pages.live-assist.*'])],
            ['key' => 'ask', 'label' => __('Ask'), 'icon' => 'ask', 'url' => Ask::canAccess() ? Ask::getUrl() : null,
                'reason' => __('Ask works on a project\'s recordings, which your role in this workspace can\'t open.'), 'active' => $is('filament.app.pages.ask')],
            ['key' => 'settings', 'label' => __('Settings'), 'icon' => 'settings', 'url' => EditProfile::settingsUrl(), 'reason' => null, 'active' => $is(self::SETTINGS_ROUTES)],
        ];
    }

    /**
     * "● Record ▾" at the top of the sidebar (since 2.0.0-p, docs/PLAN_LIBRARY_FILTERS.md; the web's Record, parity plan
     * 2): Record a voice note here (`record`), and in its menu Import audio files (`url`, the Upload page; on the Library
     * its file picker). Null in a read-only console.
     *
     * @return array{label: string, url: string, record: string, active: bool}|null
     */
    public static function upload(): ?array
    {
        if (! Upload::canAccess() || ConsoleAccess::readOnly()) {
            return null;
        }
        $routes = request()->route()?->getName() !== null;

        return ['label' => __('Record'), 'url' => Upload::getUrl(), 'record' => Upload::getUrl(['record' => 1]),
            'active' => $routes && request()->routeIs('filament.app.pages.upload')];
    }

    /**
     * Trash (Recently deleted) at the sidebar's foot (since 2.0.0-p), when the person can open it.
     *
     * @return array{label: string, url: string, active: bool}|null
     */
    public static function trash(): ?array
    {
        if (! RecentlyDeleted::canAccess()) {
            return null;
        }
        $routes = request()->route()?->getName() !== null;

        return ['label' => __('Trash'), 'url' => RecentlyDeleted::getUrl(), 'active' => $routes && request()->routeIs('filament.app.pages.recently-deleted')];
    }

    /**
     * Activity at the sidebar's foot with what needs you as its badge (as classic's sidebar has it).
     *
     * @return array{label: string, url: string, badge: string|null, active: bool}|null
     */
    public static function activity(): ?array
    {
        if (! Activity::canAccess()) {
            return null;
        }
        $routes = request()->route()?->getName() !== null;
        $badge = Activity::getNavigationBadge();

        return ['label' => __('Activity'), 'url' => Activity::getUrl(), 'badge' => filled($badge) ? (string) $badge : null,
            'active' => $routes && request()->routeIs('filament.app.pages.activity')];
    }

    /**
     * The projects under Projects, as classic's sidebar lists them: pinned first, each with how many recordings it
     * holds; the first few, the rest on the Projects page.
     *
     * @return list<array{id: string, name: string, count: int, url: string, depth: int, active: bool}>
     */
    public static function projects(int $limit = 6): array
    {
        $current = request()->route('record');
        $current = is_object($current) && isset($current->id) ? (string) $current->id : (is_scalar($current) ? (string) $current : null);
        $routes = request()->route()?->getName() !== null;
        $onProject = $routes && request()->routeIs('filament.app.resources.clients.view');

        return array_map(fn (array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'count' => $p['count'], 'url' => $p['url'], 'depth' => min(1, $p['depth']),
            'active' => $onProject && $current === $p['id'], 'pinned' => $p['pinned']], array_slice(Shell::projects(), 0, $limit));
    }

    /**
     * The sidebar's look in the new frame (Settings › Appearance › Sidebar style): Light, Dark or Soft grey. The new
     * frame starts on Soft grey, its own look, until the person chooses.
     */
    public static function style(?User $user = null): string
    {
        $user ??= auth()->user();
        $saved = $user instanceof User && is_array($user->console_preferences['shell'] ?? null) ? ($user->console_preferences['shell']['style'] ?? null) : null;

        return is_string($saved) && isset(Shell::STYLES[$saved]) ? $saved : 'soft';
    }

    /**
     * The Library's level 2: All recordings, Shared (with me), My links, Request links and Recently deleted, those the person can
     * open (Projects is a module of its own since the parity plan).
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
        if (SharedWithMe::canAccess()) {
            $places[] = ['key' => 'shared', 'label' => __('Shared'), 'url' => SharedWithMe::getUrl(), 'active' => $is('filament.app.pages.shared-with-me')];
        }
        if (Shares::canAccess()) {
            $places[] = ['key' => 'links', 'label' => __('My links'), 'url' => Shares::getUrl(), 'active' => $is('filament.app.pages.shares')];
        }
        if (RequestLinksPage::canAccess()) {
            $places[] = ['key' => 'requests', 'label' => __('Request links'), 'url' => RequestLinksPage::getUrl(), 'active' => $is('filament.app.pages.request-links')];
        }
        if (RecentlyDeleted::canAccess()) {
            $places[] = ['key' => 'trash', 'label' => __('Recently deleted'), 'url' => RecentlyDeleted::getUrl(), 'active' => $is('filament.app.pages.recently-deleted')];
        }

        return $places;
    }
}
