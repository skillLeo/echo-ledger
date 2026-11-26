<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Activity;
use App\Filament\Pages\Ask;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\Live;
use App\Filament\Pages\LiveAssist\AssistPreps;
use App\Filament\Pages\LiveAssist\AssistSessions;
use App\Filament\Pages\LiveAssist\AssistSettings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Services\Accounts\SettingsRegistry;
use App\Services\Library\LibraryFacets;
use App\Services\OnboardingChecklist;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;

/**
 * The console's three levels of navigation (docs/PLAN_NAV_3_LEVELS.md, the desktop's layout on the web): the rail of
 * places (Library, Projects, Live, Ask, Activity; at its foot Setup & health, Help, Settings and the avatar), each
 * place's panel of views and collections, and the page's own tabs or inner list. Under ui_v2.shell with
 * ui_v2.library (ConsoleV2::frame()); the earlier sidebar stays otherwise.
 */
final class NavFrame
{
    /** The rail's places in order, ⌘1…⌘6 (Settings, at the rail's foot, is the sixth). */
    public const PLACES = ['library', 'projects', 'live', 'ask', 'activity', 'settings'];

    /** The pages of each place, by route name. */
    private const ROUTES = [
        'library' => ['filament.app.resources.sessions.*', 'filament.app.pages.shared-with-me', 'filament.app.pages.shares', 'filament.app.pages.request-links',
            'filament.app.pages.recently-deleted', 'filament.app.pages.search', 'filament.app.pages.upload'],
        'projects' => ['filament.app.resources.clients.*'],
        'live' => ['filament.app.pages.live', 'filament.app.pages.live-assist.*'],
        'ask' => ['filament.app.pages.ask'],
        'activity' => ['filament.app.pages.activity'],
        'settings' => ['filament.app.auth.profile', 'filament.app.pages.upgrade*', 'filament.app.pages.security', 'filament.app.pages.usage',
            'filament.app.pages.workspace', 'filament.app.pages.help', 'filament.app.pages.live-assist.settings'],
    ];

    /**
     * Setup & health: the rail's own (at its foot, not one of Settings' groups), with no panel; its pages are its tabs.
     * Devices is a settings page by address (profile/devices), Get the apps its own.
     */
    public const SETUP_PAGES = ['devices', '@get-the-apps'];

    /**
     * Settings' groups as the panel lists them (plan section 3), each with the settings pages the web has: a group of
     * one page opens it, a group of more shows them as an inner list on the page. Recording is the apps' own.
     */
    public const SETTINGS_GROUPS = [
        'transcription' => ['Transcription & language', ['language', 'transcription-key']],
        'live' => ['Live & overlays', ['@assist-settings']],
        'privacy' => ['Privacy & data', ['privacy', 'storage']],
        'notifications' => ['Notifications', ['notifications']],
        'appearance' => ['Appearance', ['appearance']],
        'account' => ['Account & plan', ['profile', 'plan', 'security', 'workspace']],
        'shortcuts' => ['Shortcuts', ['shortcuts']],
        'about' => ['About & help', ['help']],
    ];

    /** The place the page belongs to (null on Home, which the logo opens; 'setup' for Setup & health, which has no panel). */
    public static function place(): ?string
    {
        $route = request()->route()?->getName();
        if ($route === null) {
            return null;
        }
        if (request()->routeIs('filament.app.resources.devices.*', 'filament.app.pages.get-the-apps')
            || (request()->routeIs('filament.app.auth.profile') && (string) (request()->route('subpage') ?? '') === 'devices')) {
            return 'setup';
        }
        // Live Assist's settings are Live & overlays in Settings.
        if (request()->routeIs('filament.app.pages.live-assist.settings')) {
            return 'settings';
        }
        foreach (self::ROUTES as $place => $routes) {
            if (request()->routeIs($routes)) {
                return $place;
            }
        }

        return null;
    }

    /**
     * The rail: each place with its label, icon, home, badge, shortcut and whether you are in it; a place this console
     * can't open shows with its reason (as the sidebar did).
     *
     * @return array{places: list<array<string, mixed>>, setup: array<string, mixed>|null, here: string|null}
     */
    public static function rail(): array
    {
        $here = self::place();
        $modules = collect(ConsoleV2Nav::modules())->keyBy('key');
        $user = auth()->user();
        $needs = $user instanceof User && CurrentWorkspace::id() !== null && CaptureSessionResource::canAccess()
            ? app(LibraryFacets::class)->needsYou((int) CurrentWorkspace::id(), $user->id)['count'] : 0;
        $activity = ConsoleV2Nav::activity();
        $places = [];
        foreach (self::PLACES as $i => $key) {
            $module = $key === 'activity' ? ($activity !== null ? ['label' => $activity['label'], 'icon' => 'bell', 'url' => $activity['url'], 'reason' => null] : null) : $modules->get($key);
            if ($module === null) {
                continue;
            }
            $badge = match ($key) {
                'library' => $needs > 0 ? (string) min(99, $needs) : null,
                'activity' => $activity['badge'] ?? null,
                default => null,
            };
            $places[] = ['key' => $key, 'label' => (string) $module['label'], 'icon' => (string) $module['icon'], 'url' => $module['url'], 'reason' => $module['reason'] ?? null,
                'badge' => $badge, 'shortcut' => $i + 1, 'active' => $here === $key];
        }
        $setup = self::setupPage();

        return ['places' => $places, 'setup' => $setup, 'here' => $here];
    }

    /**
     * Setup & health at the rail's foot (its own place, no panel), with a dot while setting up has a step open.
     *
     * @return array{label: string, url: string, active: bool, dot: bool}|null
     */
    private static function setupPage(): ?array
    {
        $tabs = self::setupTabs();
        if ($tabs === []) {
            return null;
        }
        $user = auth()->user();
        $dot = $user instanceof User && app(OnboardingChecklist::class)->visibleTo($user);

        return ['label' => __('Setup & health'), 'url' => $tabs[0]['url'], 'active' => self::place() === 'setup', 'dot' => $dot];
    }

    /**
     * Setup & health's pages as its tabs: Devices (signed in here and on your phone and computers) and Get the apps.
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    public static function setupTabs(): array
    {
        $context = SettingsContext::current();
        if ($context === null) {
            return [];
        }
        $routes = request()->route()?->getName() !== null;
        $subpage = $routes && request()->routeIs('filament.app.auth.profile') ? (string) (request()->route('subpage') ?? '') : null;
        $tabs = [];
        foreach (self::SETUP_PAGES as $page) {
            $item = $page === '@get-the-apps'
                ? ['label' => __('Get the apps'), 'url' => GetTheApps::getUrl(), 'active' => $routes && request()->routeIs('filament.app.pages.get-the-apps')]
                : self::settingsPage($context, $page, $subpage);
            if ($item !== null) {
                $tabs[] = ['key' => ltrim($page, '@'), 'label' => $item['label'], 'url' => $item['url'],
                    'active' => $item['active'] || ($page === 'devices' && $routes && request()->routeIs('filament.app.resources.devices.*'))];
            }
        }

        return $tabs;
    }

    /**
     * The name this browser keeps the places' memory under (frame-core memoryKey): opaque, one person in one workspace,
     * so where someone left a place never shows to someone else signing in here.
     */
    public static function memoryScope(): string
    {
        $key = (string) config('app.key');
        $person = substr(hash_hmac('sha256', 'nav-person|'.(int) auth()->id(), $key), 0, 12);
        $workspace = substr(hash_hmac('sha256', 'nav-workspace|'.(int) CurrentWorkspace::id(), $key), 0, 8);

        return $person.'.'.$workspace;
    }

    /**
     * Settings' groups the person can open: key, label, where it opens, its pages (for the inner list) and whether you
     * are in it.
     *
     * @return list<array{key: string, label: string, url: string, active: bool, pages: list<array{label: string, url: string, active: bool}>}>
     */
    public static function settingsGroups(): array
    {
        $context = SettingsContext::current();
        if ($context === null) {
            return [];
        }
        $subpage = request()->routeIs('filament.app.auth.profile') ? (string) (request()->route('subpage') ?? '') : null;
        $routes = request()->route()?->getName() !== null;
        $groups = [];
        foreach (self::SETTINGS_GROUPS as $key => [$label, $pages]) {
            $items = [];
            foreach ($pages as $page) {
                $item = match ($page) {
                    '@assist-settings' => AssistSettings::canAccess() ? ['label' => __('Live Assist'), 'url' => AssistSettings::getUrl(),
                        'active' => $routes && request()->routeIs('filament.app.pages.live-assist.settings')] : null,
                    default => self::settingsPage($context, $page, $subpage),
                };
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            if ($items === []) {
                continue;
            }
            $active = collect($items)->contains('active', true) || ($key === 'account' && $routes && request()->routeIs(['filament.app.pages.upgrade*', 'filament.app.pages.security', 'filament.app.pages.usage', 'filament.app.pages.workspace']))
                || ($key === 'about' && $routes && request()->routeIs('filament.app.pages.help'));
            $groups[] = ['key' => $key, 'label' => __($label), 'url' => $items[0]['url'], 'active' => $active, 'pages' => $items];
        }

        return $groups;
    }

    /** @return array{label: string, url: string, active: bool}|null */
    private static function settingsPage(SettingsContext $context, string $page, ?string $subpage): ?array
    {
        $entry = SettingsRegistry::byId()[SettingsRegistry::PAGES[$page] ?? ''] ?? null;
        if ($entry === null || ! $context->available($entry)) {
            return null;
        }

        return ['label' => __(SettingsRegistry::titleOf($entry)), 'url' => $context->url($entry), 'active' => $subpage === $page];
    }

    /**
     * The settings group the page belongs to, with its pages, when it has more than one (the page's inner list).
     *
     * @return array{key: string, label: string, url: string, active: bool, pages: list<array{label: string, url: string, active: bool}>}|null
     */
    public static function settingsInnerList(): ?array
    {
        $group = collect(self::settingsGroups())->firstWhere('active', true);

        return is_array($group) && count($group['pages']) > 1 ? $group : null;
    }

    /**
     * Projects' panel: All projects, Pinned, Recent, + New project, then the projects as a tree with their counts.
     *
     * @return array{views: list<array<string, mixed>>, create: string|null, projects: list<array<string, mixed>>}
     */
    public static function projectsPanel(): array
    {
        if (! ClientResource::canAccess()) {
            return ['views' => [], 'create' => null, 'projects' => []];
        }
        $routes = request()->route()?->getName() !== null;
        $onList = $routes && request()->routeIs('filament.app.resources.clients.index');
        $view = $onList ? (string) request()->query('show', '') : null;
        $index = ClientResource::getUrl('index');
        $views = [];
        foreach (['' => __('All projects'), 'pinned' => __('Pinned'), 'recent' => __('Recent')] as $key => $label) {
            $views[] = ['key' => $key === '' ? 'all' : $key, 'label' => $label, 'url' => $key === '' ? $index : $index.'?show='.$key, 'active' => $view === $key];
        }
        $readOnly = ConsoleAccess::readOnly();

        return ['views' => $views, 'create' => ! $readOnly && ClientResource::canCreate() ? ClientResource::getUrl('create') : null, 'projects' => ConsoleV2Nav::projects(500)];
    }

    /**
     * Live's panel: recording calls (in the apps), Live Assist (Upcoming, Past reports, Practice) where the person has
     * it, and the apps' live tools (Live Translate, Dictation, Captions), each on the Live page.
     *
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    public static function livePanel(): array
    {
        if (! Live::canAccess()) {
            return [];
        }
        $routes = request()->route()?->getName() !== null;
        $onLive = $routes && request()->routeIs('filament.app.pages.live');
        $live = Live::getUrl();
        $groups = [['title' => __('Record'), 'items' => [
            ['key' => 'calls', 'label' => __('Call recording'), 'icon' => 'phone', 'url' => $live.'#record', 'note' => __('In the apps'), 'active' => $onLive],
        ]]];
        if (AssistSessions::canAccess()) {
            // The Show filter from the address, only as a word (?filters[kind][value][]=x is a list: nothing chosen).
            $filters = $routes && request()->routeIs('filament.app.pages.live-assist.sessions') ? request()->query('filters') : null;
            $value = is_array($filters) && is_array($filters['kind'] ?? null) ? ($filters['kind']['value'] ?? null) : null;
            $kind = is_string($value) ? $value : null;
            $groups[] = ['title' => __('Live Assist'), 'items' => [
                ['key' => 'upcoming', 'label' => __('Upcoming'), 'icon' => 'clock', 'url' => AssistPreps::getUrl(), 'active' => $routes && request()->routeIs('filament.app.pages.live-assist.preps')],
                ['key' => 'reports', 'label' => __('Past reports'), 'icon' => 'transcript', 'url' => self::assistSessionsUrl('call'), 'active' => $kind === 'call'],
                ['key' => 'practice', 'label' => __('Practice'), 'icon' => 'mic', 'url' => self::assistSessionsUrl('practice'), 'active' => $kind === 'practice'],
            ]];
        }
        $groups[] = ['title' => __('Tools'), 'items' => [
            ['key' => 'translate', 'label' => __('Live Translate'), 'icon' => 'globe', 'url' => $live.'#translate', 'note' => null, 'active' => false],
            ['key' => 'dictation', 'label' => __('Dictation'), 'icon' => 'mic', 'url' => $live.'#dictation', 'note' => null, 'active' => false],
            ['key' => 'captions', 'label' => __('Captions'), 'icon' => 'captions', 'url' => $live.'#captions', 'note' => null, 'active' => false],
        ]];

        return $groups;
    }

    /**
     * Live Assist's page tabs (section 4): Upcoming (the preps), Past reports (the calls) and Practice, from the page
     * being drawn (its table's Show filter on the sessions page).
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    public static function liveAssistTabs(mixed $page): array
    {
        // With no Show filter the page lists calls and Practice together: neither tab is the open one then.
        $value = $page instanceof AssistSessions && is_array($page->tableFilters['kind'] ?? null) ? ($page->tableFilters['kind']['value'] ?? null) : null;
        $kind = is_string($value) ? $value : null;

        return [
            ['key' => 'upcoming', 'label' => __('Upcoming'), 'url' => AssistPreps::getUrl(), 'active' => $page instanceof AssistPreps],
            ['key' => 'reports', 'label' => __('Past reports'), 'url' => self::assistSessionsUrl('call'), 'active' => $kind === 'call'],
            ['key' => 'practice', 'label' => __('Practice'), 'url' => self::assistSessionsUrl('practice'), 'active' => $kind === 'practice'],
        ];
    }

    /** Live Assist's sessions with one kind shown: past reports of calls, or Practice. */
    public static function assistSessionsUrl(string $kind): string
    {
        return AssistSessions::getUrl().'?'.http_build_query(['filters' => ['kind' => ['value' => $kind]]]);
    }

    /**
     * Ask's panel: + New question only. The web keeps no list of earlier questions (each answer is on the page that
     * asked it), and which project to ask about is the page's own picker, so the panel doesn't list the projects again.
     *
     * @return array{new: string|null}
     */
    public static function askPanel(): array
    {
        return ['new' => Ask::canAccess() ? Ask::getUrl() : null];
    }

    /**
     * Activity's panel: All, Needs you (with its count, strong) and Done.
     *
     * @return list<array<string, mixed>>
     */
    public static function activityPanel(): array
    {
        $activity = ConsoleV2Nav::activity();
        if ($activity === null) {
            return [];
        }
        $view = request()->routeIs('filament.app.pages.activity') ? (string) request()->query('show', '') : null;
        $items = [];
        foreach (['' => [__('All'), 'bell'], 'needs_you' => [__('Needs you'), 'alert'], 'done' => [__('Done'), 'check']] as $key => [$label, $icon]) {
            $items[] = ['key' => $key === '' ? 'all' : $key, 'label' => $label, 'icon' => $icon, 'url' => $key === '' ? $activity['url'] : $activity['url'].'?show='.$key,
                'count' => $key === 'needs_you' ? (int) ($activity['badge'] ?? 0) : 0, 'strong' => $key === 'needs_you', 'active' => $view === $key];
        }

        return $items;
    }

    /** Where the logo goes: Home. */
    public static function homeUrl(): string
    {
        return Dashboard::getUrl();
    }

    /** Settings' home. */
    public static function settingsUrl(): string
    {
        return EditProfile::settingsUrl();
    }
}
