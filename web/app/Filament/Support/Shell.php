<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Admin\Pages\Dashboard as AdminDashboard;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Activity;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\Search;
use App\Filament\Pages\Security;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Shares;
use App\Filament\Pages\Upload;
use App\Filament\Pages\Usage;
use App\Filament\Pages\WorkspaceSettings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use App\Services\LibraryOrganiser;
use App\Services\Sharing\Teammates;
use App\Services\Staff\StaffAccess;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The console's app shell (since v1.6, P4): what the sidebar, its "Find a page", the Library's column, the top bar and
 * the command palette show, and the person's own choices for them (`console_preferences.shell`): the sidebar's width
 * (200–320 px, 240 by default), whether it is folded to the 64 px rail, its style (Light, Dark or Soft grey), which of
 * its groups are open, the list density and 12- or 24-hour times. Only pages the person may open are listed; counts are
 * the person's own recordings in the current workspace, read once per request.
 */
final class Shell
{
    public const WIDTH_DEFAULT = 240;

    public const WIDTH_MIN = 200;

    public const WIDTH_MAX = 320;

    public const RAIL_WIDTH = 64;

    /** @var array<string, string> */
    public const STYLES = ['light' => 'Light', 'dark' => 'Dark', 'soft' => 'Soft grey'];

    /** @var array<string, string> */
    public const DENSITIES = ['comfortable' => 'Comfortable', 'compact' => 'Compact'];

    /** @var array<string, string> */
    public const CLOCKS = ['12h' => '12-hour (2:30 PM)', '24h' => '24-hour (14:30)'];

    /** @var array<string, string> the Library as a list (the phone's rows) or a table */
    public const LAYOUTS = ['list' => 'List', 'table' => 'Table'];

    /** The sidebar's groups that open to show what is inside. */
    public const GROUPS = ['library', 'projects', 'shared'];

    /** The Library's views in the column, in the desktop's order; Today, This week and Bookmarked while they hold something. */
    public const VIEWS = ['waiting' => 'Waiting', 'pinned' => 'Pinned', 'failed' => 'Failed uploads', 'today' => 'Today', 'this_week' => 'This week', 'bookmarked' => 'Bookmarked'];

    /** The views shown even when empty (the desktop's three). */
    private const ALWAYS = ['waiting', 'pinned', 'failed'];

    /** @var array<string, string> */
    public const VIEW_ICONS = ['all' => 'squares-2x2', 'waiting' => 'clock', 'pinned' => 'bookmark', 'failed' => 'exclamation-circle', 'today' => 'calendar', 'this_week' => 'calendar-days', 'bookmarked' => 'flag'];

    /** The smart folders in the column, with the desktop's names (Meet, SIM). */
    public const FOLDERS = [
        'inbox' => 'Inbox', 'whatsapp' => 'WhatsApp', 'whatsapp_business' => 'WhatsApp Business', 'google_meet' => 'Meet', 'zoom' => 'Zoom',
        'teams' => 'Teams', 'phone' => 'SIM', 'voice_notes' => 'Voice notes', 'imported' => 'Imported', 'shared' => 'Shared with me',
    ];

    /**
     * The person's shell choices, each checked, or its default.
     *
     * @return array{width: int, collapsed: bool, style: string, open: list<string>, density: string, clock: string, layout: string, language: string}
     */
    public static function preferences(?User $user = null): array
    {
        $user ??= self::user();
        $saved = is_array($user?->console_preferences) && is_array($user->console_preferences['shell'] ?? null) ? $user->console_preferences['shell'] : [];
        $width = is_int($saved['width'] ?? null) ? $saved['width'] : self::WIDTH_DEFAULT;
        $open = is_array($saved['open'] ?? null) ? array_values(array_intersect(self::GROUPS, $saved['open'])) : ['projects', 'shared'];

        return [
            'width' => max(self::WIDTH_MIN, min(self::WIDTH_MAX, $width)),
            'collapsed' => ($saved['collapsed'] ?? false) === true,
            'style' => is_string($saved['style'] ?? null) && isset(self::STYLES[$saved['style']]) ? $saved['style'] : 'light',
            'open' => $open,
            'density' => is_string($saved['density'] ?? null) && isset(self::DENSITIES[$saved['density']]) ? $saved['density'] : 'comfortable',
            'clock' => is_string($saved['clock'] ?? null) && isset(self::CLOCKS[$saved['clock']]) ? $saved['clock'] : '12h',
            'layout' => is_string($saved['layout'] ?? null) && isset(self::LAYOUTS[$saved['layout']]) ? $saved['layout'] : 'list',
            'language' => is_string($saved['language'] ?? null) && LanguageChoice::normalize($saved['language']) !== null ? $saved['language'] : '',
        ];
    }

    /**
     * Keeps one choice; an unknown key or value is refused (false) and nothing is saved.
     */
    public static function remember(User $user, string $key, mixed $value): bool
    {
        $clean = match ($key) {
            'width' => is_numeric($value) ? max(self::WIDTH_MIN, min(self::WIDTH_MAX, (int) $value)) : null,
            'collapsed' => is_bool($value) ? $value : null,
            'style' => is_string($value) && isset(self::STYLES[$value]) ? $value : null,
            'density' => is_string($value) && isset(self::DENSITIES[$value]) ? $value : null,
            'clock' => is_string($value) && isset(self::CLOCKS[$value]) ? $value : null,
            'layout' => is_string($value) && isset(self::LAYOUTS[$value]) ? $value : null,
            // The language new uploads start in ('' = Automatic).
            'language' => $value === '' ? '' : (is_string($value) && LanguageChoice::normalize($value) === $value ? $value : null),
            'open' => is_array($value) ? array_values(array_intersect(self::GROUPS, array_filter($value, 'is_string'))) : null,
            default => null,
        };
        if ($clean === null) {
            return false;
        }
        $preferences = is_array($user->console_preferences) ? $user->console_preferences : [];
        $shell = is_array($preferences['shell'] ?? null) ? $preferences['shell'] : [];
        $shell[$key] = $clean;
        $preferences['shell'] = $shell;
        $user->forceFill(['console_preferences' => $preferences])->save();

        return true;
    }

    /** The sidebar's width for the page's first paint, in CSS. */
    public static function sidebarWidth(): string
    {
        return self::preferences()['width'].'px';
    }

    /** The PHP date format of a clock time in the person's choice: "2:30 PM" or "14:30". */
    public static function timeFormat(): string
    {
        return self::preferences()['clock'] === '24h' ? 'H:i' : 'g:i A';
    }

    /** Whether this is the customer app (the admin panel has its own navigation in the same frame). */
    public static function inApp(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'app';
    }

    /** "⌘K" on a Mac, else "Ctrl K". */
    public static function paletteKeys(): string
    {
        return str_contains((string) request()->userAgent(), 'Mac OS X') ? '⌘K' : 'Ctrl K';
    }

    /**
     * The places of the Library: every recording, its views and its smart folders, with how many recordings each
     * holds. The Inbox takes dropped recordings out of their project; Pinned pins them.
     *
     * @return list<array{key: string, label: string, icon: string, count: int|null, url: string, tab: string|null, folder: string|null, drop: string|null}>
     */
    public static function libraryPlaces(): array
    {
        if (! CaptureSessionResource::canAccess()) {
            return [];
        }
        $counts = self::counts();
        $base = CaptureSessionResource::getUrl('index');
        $places = [['key' => 'all', 'label' => 'All', 'icon' => self::VIEW_ICONS['all'], 'count' => $counts['all'], 'url' => $base, 'tab' => null, 'folder' => null, 'drop' => null]];
        foreach (self::VIEWS as $view => $label) {
            if (! in_array($view, self::ALWAYS, true) && ($counts['views'][$view] ?? 0) === 0) {
                continue;
            }
            $places[] = ['key' => $view, 'label' => $label, 'icon' => self::VIEW_ICONS[$view], 'count' => $counts['views'][$view] ?? 0,
                'url' => $base.'?tab='.$view, 'tab' => $view, 'folder' => null, 'drop' => $view === 'pinned' && ! ConsoleAccess::readOnly() ? 'pinned' : null];
        }
        foreach (self::FOLDERS as $folder => $label) {
            $count = $counts['folders'][$folder] ?? 0;
            if ($folder === 'shared') {
                if (! SharedWithMe::canAccess()) {
                    continue;
                }
                $places[] = ['key' => 'shared', 'label' => $label, 'icon' => ListCaptureSessions::FOLDER_ICONS['shared'], 'count' => $count, 'url' => SharedWithMe::getUrl(), 'tab' => null, 'folder' => 'shared', 'drop' => null];

                continue;
            }
            if ($folder !== 'inbox' && $count === 0) {
                continue;
            }
            $places[] = ['key' => 'folder:'.$folder, 'label' => $label, 'icon' => ListCaptureSessions::FOLDER_ICONS[$folder], 'count' => $count,
                'url' => $base.'?folder='.$folder, 'tab' => null, 'folder' => $folder, 'drop' => $folder === 'inbox' && ! ConsoleAccess::readOnly() ? 'inbox' : null];
        }

        return $places;
    }

    /**
     * The person's projects in the current workspace, top level first with their sub-projects under them, each with
     * how many recordings it holds.
     *
     * @return list<array{id: string, name: string, pinned: bool, depth: int, count: int, url: string}>
     */
    public static function projects(): array
    {
        if (! ClientResource::canAccess()) {
            return [];
        }
        $key = 'el.shell.projects';
        $cached = request()->attributes->get($key);
        if (is_array($cached)) {
            /** @var list<array{id: string, name: string, pinned: bool, depth: int, count: int, url: string}> $cached */
            return $cached;
        }
        $workspace = (int) CurrentWorkspace::id();
        $clients = Client::query()->where('workspace_id', $workspace)->where('user_id', CurrentWorkspace::userId())
            ->orderByDesc('pinned')->orderBy('name')->limit(200)->get(['id', 'name', 'pinned', 'parent_id']);
        $counts = CaptureSession::query()->where('workspace_id', $workspace)->ofPerson()->whereNotNull('client_id')
            ->select('client_id', DB::raw('COUNT(*) as n'))->groupBy('client_id')->pluck('n', 'client_id');
        $children = [];
        foreach ($clients as $client) {
            $children[(string) ($client->parent_id ?? '')][] = $client;
        }
        $ids = $clients->pluck('id')->all();
        $out = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$out, $children, $counts): void {
            foreach ($children[$parent] ?? [] as $client) {
                $out[] = ['id' => (string) $client->id, 'name' => (string) $client->name, 'pinned' => (bool) $client->pinned, 'depth' => $depth,
                    'count' => (int) ($counts[$client->id] ?? 0), 'url' => ClientResource::getUrl('view', ['record' => $client])];
                if ($depth < 3) {
                    $walk((string) $client->id, $depth + 1);
                }
            }
        };
        $walk('', 0);
        // A sub-project whose parent is not listed (another person's, or past the limit) shows at the top level.
        foreach ($children as $parent => $list) {
            if ($parent !== '' && ! in_array($parent, $ids, true)) {
                foreach ($list as $client) {
                    $out[] = ['id' => (string) $client->id, 'name' => (string) $client->name, 'pinned' => (bool) $client->pinned, 'depth' => 0,
                        'count' => (int) ($counts[$client->id] ?? 0), 'url' => ClientResource::getUrl('view', ['record' => $client])];
                }
            }
        }
        request()->attributes->set($key, $out);

        return $out;
    }

    /** @return list<array{id: string, name: string, pinned: bool, depth: int, count: int, url: string}> */
    public static function pinnedProjects(): array
    {
        return array_values(array_filter(self::projects(), fn (array $p): bool => $p['pinned']));
    }

    /**
     * Shared: what others sent the person, the links they made and their request links.
     *
     * @return list<array{key: string, label: string, icon: string, url: string, route: string}>
     */
    public static function sharedPlaces(): array
    {
        $list = [];
        if (SharedWithMe::canAccess()) {
            $list[] = ['key' => 'with-me', 'label' => 'With me', 'icon' => 'users', 'url' => SharedWithMe::getUrl(), 'route' => 'filament.app.pages.shared-with-me'];
        }
        if (Shares::canAccess()) {
            $list[] = ['key' => 'links', 'label' => 'My links', 'icon' => 'link', 'url' => Shares::getUrl(), 'route' => 'filament.app.pages.shares'];
        }
        if (RequestLinksPage::canAccess()) {
            $list[] = ['key' => 'requests', 'label' => 'Request links', 'icon' => 'inbox-arrow-down', 'url' => RequestLinksPage::getUrl(), 'route' => 'filament.app.pages.request-links'];
        }

        return $list;
    }

    /**
     * Every page "Find a page" and the command palette know, the person's own only: label, group, where it goes and
     * the words it answers to.
     *
     * @return list<array{id: string, label: string, group: string, url: string, words: string}>
     */
    public static function pages(): array
    {
        $account = Filament::getPanel('app')->getProfileUrl() ?? '/app/profile';
        $list = [
            ['home', 'Home', '', Dashboard::getUrl(panel: 'app'), 'dashboard start overview', true],
            ['library', 'Library', '', CaptureSessionResource::getUrl('index', panel: 'app'), 'recordings calls voice notes', CaptureSessionResource::canAccess()],
            ['projects', 'Projects', '', ClientResource::getUrl('index', panel: 'app'), 'folders customers', ClientResource::canAccess()],
            ['activity', 'Activity', '', Activity::getUrl(panel: 'app'), 'notifications bell problems', Activity::canAccess()],
            ['upload', 'Upload', '', Upload::getUrl(panel: 'app'), 'import files add audio video', Upload::canAccess()],
            ['search', 'Search', '', Search::getUrl(panel: 'app'), 'find words transcript', Search::canAccess()],
            ['shared-with-me', 'Shared with me', 'Shared', SharedWithMe::getUrl(panel: 'app'), 'sent teammates received', SharedWithMe::canAccess()],
            ['links', 'My links', 'Shared', Shares::getUrl(panel: 'app'), 'share links public', Shares::canAccess()],
            ['request-links', 'Request links', 'Shared', RequestLinksPage::getUrl(panel: 'app'), 'ask files upload link', RequestLinksPage::canAccess()],
            ['recently-deleted', 'Recently deleted', 'Library', RecentlyDeleted::getUrl(panel: 'app'), 'trash restore bin', RecentlyDeleted::canAccess()],
            ['get-the-apps', 'Get the apps', '', GetTheApps::getUrl(panel: 'app'), 'download install phone computer record', true],
            ['profile', 'Profile', 'Account', $account.'?tab=profile', 'name email account', true],
            ['sign-in', 'Password & sign-in methods', 'Account', $account.'?tab=sign-in', 'password two-step passkey google security', true],
            ['devices', 'Devices', 'Account', DeviceResource::canAccess() ? DeviceResource::getUrl('index', panel: 'app') : $account.'?tab=devices', 'phones computers sign out', true],
            ['notifications', 'Notifications', 'Account', $account.'?tab=notifications', 'emails alerts', true],
            ['preferences', 'Preferences', 'Account', $account.'?tab=preferences', 'sidebar style density clock 24-hour shortcuts theme', true],
            ['plan', 'Plan & usage', 'Account', $account.'?tab=plan', 'upgrade pro business free minutes', true],
            ['usage', 'Usage', 'Account', Usage::getUrl(panel: 'app'), 'minutes cost budget', Usage::canAccess()],
            ['privacy', 'Privacy & data', 'Account', $account.'?tab=privacy', 'export delete account support access', true],
            ['security', 'Security', 'Account', Security::getUrl(panel: 'app'), 'audit log passkeys browsers', Security::canAccess()],
            ['workspace', 'Workspace', 'Account', WorkspaceSettings::getUrl(panel: 'app'), 'members invite team roles', WorkspaceSettings::canAccess()],
            ['admin', 'Admin panel', 'Staff', AdminDashboard::getUrl(panel: 'admin'), 'staff customers plans', StaffAccess::isStaff()],
        ];
        $pages = [];
        foreach ($list as [$id, $label, $group, $url, $words, $allowed]) {
            if ($allowed) {
                $pages[] = ['id' => $id, 'label' => $label, 'group' => $group, 'url' => $url, 'words' => $words];
            }
        }
        foreach (self::libraryPlaces() as $place) {
            if ($place['key'] !== 'all') {
                $pages[] = ['id' => 'place-'.$place['key'], 'label' => $place['label'], 'group' => 'Library', 'url' => $place['url'], 'words' => 'smart folder view'];
            }
        }
        foreach (array_slice(self::projects(), 0, 60) as $project) {
            $pages[] = ['id' => 'project-'.$project['id'], 'label' => $project['name'], 'group' => 'Projects', 'url' => $project['url'], 'words' => 'project'];
        }

        return $pages;
    }

    /**
     * The page pattern's trail (since v1.6, P4.4) for pages that draw none themselves: the section, then the page,
     * as [url => label] with the current page last and without a link (its key is an integer). By the page's class,
     * so a Livewire update draws the same trail as the first load.
     *
     * @return array<int|string, string>
     */
    public static function crumbs(string $page, string $title): array
    {
        $profile = Filament::getPanel('app')->getProfileUrl() ?? '/app/profile';
        $library = CaptureSessionResource::canAccess() ? CaptureSessionResource::getUrl('index', panel: 'app') : null;
        $shared = self::sharedPlaces()[0]['url'] ?? null;
        $section = match ($page) {
            ListCaptureSessions::class, ListClients::class, Activity::class, EditProfile::class => [],
            Upload::class, RecentlyDeleted::class, Search::class => $library !== null ? [$library => 'Library'] : [],
            SharedWithMe::class, Shares::class, RequestLinksPage::class => $shared !== null ? [$shared => 'Shared'] : [],
            Security::class, ListDevices::class, Usage::class, WorkspaceSettings::class => [$profile => 'Account'],
            GetTheApps::class => [Dashboard::getUrl(panel: 'app') => 'Home'],
            default => null,
        };
        if ($section === null) {
            return [];
        }
        $label = match ($page) {
            SharedWithMe::class => 'With me',
            Shares::class => 'My links',
            EditProfile::class => 'Account',
            default => $title,
        };

        return [...$section, $label];
    }

    /** The one-line subtitle of a page that has none of its own (P4.4). */
    public static function subtitle(string $page): ?string
    {
        $all = fn (): int => self::counts()['all'];

        return match ($page) {
            ListCaptureSessions::class => ($all() === 1 ? '1 recording' : number_format($all()).' recordings').' from your phone, your computer and the web.',
            ListClients::class => 'A folder for each customer: their calls, files, notes and the words to get right.',
            Activity::class => 'Uploads, transcripts, devices and what needs you.',
            SharedWithMe::class => 'Recordings and projects your teammates sent you.',
            Shares::class => 'The links you made, who opened them, and Stop sharing.',
            RequestLinksPage::class => 'Links people use to send you files, without an account.',
            RecentlyDeleted::class => 'Deleted recordings stay here for 48 hours; restore one or delete it for good.',
            Search::class => 'Find a word in every transcript; a result plays from that moment.',
            Security::class => 'Passkeys, the browsers signed in, and the log of sign-ins and changes.',
            ListDevices::class => 'Your phones and computers: rename one, sign it out, or sign in a new one.',
            Usage::class => 'Minutes transcribed and what they cost, this month and before.',
            EditProfile::class => 'Your profile, sign-in, devices, notifications, preferences, plan and data.',
            default => null,
        };
    }

    /**
     * How many recordings each view and folder holds, read once per request.
     *
     * @return array{all: int, views: array<string, int>, folders: array<string, int>}
     */
    public static function counts(): array
    {
        $key = 'el.shell.counts';
        $cached = request()->attributes->get($key);
        if (is_array($cached)) {
            /** @var array{all: int, views: array<string, int>, folders: array<string, int>} $cached */
            return $cached;
        }
        $workspace = (int) CurrentWorkspace::id();
        $organiser = app(LibraryOrganiser::class);
        try {
            $views = $organiser->counts($workspace);
            $folders = $organiser->folderCounts($workspace);
            $user = self::user();
            $folders['shared'] = $user instanceof User ? app(Teammates::class)->sharedCount($user) : 0;
            $all = CaptureSession::query()->where('workspace_id', $workspace)->ofPerson()->count();
        } catch (Throwable) {
            $views = $folders = [];
            $all = 0;
        }
        $counts = ['all' => $all, 'views' => $views, 'folders' => $folders];
        request()->attributes->set($key, $counts);

        return $counts;
    }

    /** Forgets the counts read for this request (after a recording was moved). */
    public static function forget(): void
    {
        request()->attributes->remove('el.shell.counts');
        request()->attributes->remove('el.shell.projects');
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
