{{--
    The sidebar (since v1.6, P4.1), the desktop app's frame: the brand, "Find a page", the groups, and at the bottom
    Help & shortcuts (?), Account and Collapse sidebar ([). 240 px, 200–320 by dragging its edge (double-click: 240),
    a 64 px icon rail with "[" or the button; Light, Dark or Soft grey (Account → Preferences). Remembered per person.
    It keeps Filament's sidebar contract (filamentSidebar, $store.sidebar), so on phones it is the drawer "More" opens.
--}}
@php
    use App\Filament\Support\Shell;
    use App\Filament\Pages\Activity;
    use App\Filament\Pages\Dashboard;
    use App\Filament\Pages\Upload;
    use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
    use App\Filament\Resources\Clients\ClientResource;
    use App\Services\Workspaces\ConsoleAccess;

    $prefs = Shell::preferences();
    $inApp = Shell::inApp();
    $profileUrl = filament()->getProfileUrl() ?? \Filament\Facades\Filament::getPanel('app')->getProfileUrl();
    $onLibrary = request()->routeIs('filament.app.resources.sessions.index');
    $place = $onLibrary ? (filled(request()->query('folder')) ? 'folder:'.request()->query('folder') : (filled(request()->query('tab')) && request()->query('tab') !== 'all' ? (string) request()->query('tab') : 'all')) : null;
    if ($inApp) {
        $places = Shell::libraryPlaces();
        $pinned = Shell::pinnedProjects();
        $shared = Shell::sharedPlaces();
        $activityBadge = Activity::canAccess() ? Activity::getNavigationBadge() : null;
        $sharedActive = collect($shared)->contains(fn (array $s): bool => request()->routeIs($s['route']));
    }
    $pages = $inApp ? Shell::pages() : collect(filament()->getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => [
        'id' => md5((string) $item->getUrl()), 'label' => (string) $item->getLabel(), 'group' => (string) $group->getLabel(), 'url' => (string) $item->getUrl(), 'words' => '',
    ]))->values()->all();
    $config = [
        'width' => $prefs['width'], 'min' => Shell::WIDTH_MIN, 'max' => Shell::WIDTH_MAX, 'usual' => Shell::WIDTH_DEFAULT,
        'collapsed' => $prefs['collapsed'], 'open' => $prefs['open'], 'pages' => $pages, 'place' => $place,
    ];
@endphp
<div>
    {{-- format-ignore-start --}}
    <div
        x-data="filamentSidebar({ hasTopNavigation: false, isSidebarCollapsibleOnDesktop: true, isSidebarFullyCollapsibleOnDesktop: false })"
        x-cloak
        x-bind:class="{ 'fi-sidebar-open': $store.sidebar.isOpen }"
        x-bind:inert="isHidden"
        x-effect="focusInitialNavigation()"
        x-on:keydown.tab="handleTab($event)"
        x-on:keydown.escape.document="handleEscape($event)"
        id="fi-main-sidebar"
        class="fi-sidebar fi-main-sidebar el-sb"
        data-style="{{ $prefs['style'] }}"
        data-panel="{{ filament()->getId() }}"
    >
        <div class="el-sb-inner" x-data="elShellSidebar(@js($config))" x-on:el-place.window="place = $event.detail.place ?? place">
            <header class="el-sb-brand">
                <a href="{{ filament()->getHomeUrl() }}" class="el-sb-logo" aria-label="{{ $inApp ? 'EchoLedger home' : 'Admin panel home' }}">
                    <x-filament-panels::logo />
                </a>
                <button type="button" class="el-sb-icon-btn el-sb-close" x-on:click="$store.sidebar.close()" aria-label="Close the menu">
                    @svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])
                </button>
            </header>

            <div class="el-sb-find">
                <button type="button" class="el-sb-item el-sb-find-rail" x-show="rail()" x-on:click="expand(true)" aria-label="Find a page" title="Find a page">
                    @svg('heroicon-o-magnifying-glass', ['class' => 'el-sb-icon', 'aria-hidden' => 'true'])
                </button>
                <label class="el-sb-find-box" x-show="! rail()">
                    @svg('heroicon-o-magnifying-glass', ['aria-hidden' => 'true'])
                    <input type="search" x-ref="find" x-model="query" placeholder="Find a page" aria-label="Find a page" autocomplete="off"
                        role="combobox" aria-controls="el-sb-found" x-bind:aria-expanded="query.trim() !== '' ? 'true' : 'false'"
                        x-bind:aria-activedescendant="found()[chosen] ? 'el-sb-found-' + chosen : null"
                        x-on:input="chosen = 0" x-on:keydown="findKey($event)">
                </label>
            </div>

            <ul id="el-sb-found" class="el-sb-found" role="listbox" aria-label="Pages" x-show="query.trim() !== '' && ! rail()" x-cloak>
                <template x-for="(page, i) in found()" :key="page.id">
                    <li role="option" x-bind:id="'el-sb-found-' + i" x-bind:aria-selected="i === chosen ? 'true' : 'false'" x-bind:class="{ 'el-on': i === chosen }"
                        x-on:mouseenter="chosen = i" x-on:mousedown.prevent="go(page)">
                        <span class="el-sb-found-label" x-text="page.label"></span>
                        <span class="el-sb-found-group" x-text="page.group"></span>
                    </li>
                </template>
                <li class="el-sb-found-empty" role="presentation" x-show="found().length === 0">No page matches.</li>
            </ul>

            <nav class="el-sb-nav" aria-label="Main" x-show="query.trim() === '' || rail()">
                @if ($inApp)
                    @include('filament.shell.sidebar-item', ['label' => 'Home', 'url' => Dashboard::getUrl(), 'icon' => 'home', 'active' => request()->routeIs('filament.app.pages.dashboard')])

                    @if (CaptureSessionResource::canAccess())
                        <div class="el-sb-group">
                            <div class="el-sb-row">
                                @include('filament.shell.sidebar-item', ['label' => 'Library', 'url' => CaptureSessionResource::getUrl('index'), 'icon' => 'film', 'active' => request()->routeIs('filament.app.resources.sessions.*')])
                                @include('filament.shell.sidebar-expander', ['group' => 'library', 'label' => "the Library's smart folders"])
                            </div>
                            <ul class="el-sb-sub" x-show="isOpen('library') && ! rail()" aria-label="Smart folders" x-cloak>
                                @foreach ($places as $p)
                                    <li @if ($p['drop']) data-el-drop="{{ $p['drop'] }}" @endif>
                                        <a href="{{ $p['url'] }}" class="el-sb-subitem" x-bind:class="{ 'el-on': place === @js($p['key']) }"
                                            x-bind:aria-current="place === @js($p['key']) ? 'true' : null">
                                            @svg('heroicon-o-'.$p['icon'], ['aria-hidden' => 'true'])
                                            <span class="el-sb-label">{{ $p['label'] }}</span>
                                            @if ($p['count'] !== null && $p['count'] > 0)
                                                <span class="el-sb-count">{{ number_format($p['count']) }}</span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (ClientResource::canAccess())
                        <div class="el-sb-group">
                            <div class="el-sb-row">
                                @include('filament.shell.sidebar-item', ['label' => 'Projects', 'url' => ClientResource::getUrl('index'), 'icon' => 'folder', 'active' => request()->routeIs('filament.app.resources.clients.*')])
                                @if ($pinned !== [])
                                    @include('filament.shell.sidebar-expander', ['group' => 'projects', 'label' => 'pinned projects'])
                                @endif
                            </div>
                            @if ($pinned !== [])
                                <ul class="el-sb-sub" x-show="isOpen('projects') && ! rail()" aria-label="Pinned projects" x-cloak>
                                    @foreach ($pinned as $project)
                                        @php($here = request()->routeIs('filament.app.resources.clients.*') && (string) (request()->route('record') instanceof \Illuminate\Database\Eloquent\Model ? request()->route('record')->getKey() : request()->route('record')) === $project['id'])
                                        <li @if (! ConsoleAccess::readOnly()) data-el-drop="project:{{ $project['id'] }}" @endif>
                                            <a href="{{ $project['url'] }}" @class(['el-sb-subitem', 'el-on' => $here]) @if ($here) aria-current="true" @endif title="{{ $project['name'] }}">
                                                @svg('heroicon-o-folder', ['aria-hidden' => 'true'])
                                                <span class="el-sb-label">{{ $project['name'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

                    @if (Activity::canAccess())
                        @include('filament.shell.sidebar-item', ['label' => 'Activity', 'url' => Activity::getUrl(), 'icon' => 'bell', 'active' => request()->routeIs('filament.app.pages.activity'), 'badge' => $activityBadge])
                    @endif

                    @if ($shared !== [])
                        <div class="el-sb-group">
                            <div class="el-sb-row">
                                @include('filament.shell.sidebar-item', ['label' => 'Shared', 'url' => $shared[0]['url'], 'icon' => 'share', 'active' => $sharedActive])
                                @include('filament.shell.sidebar-expander', ['group' => 'shared', 'label' => 'what is shared'])
                            </div>
                            <ul class="el-sb-sub" x-show="isOpen('shared') && ! rail()" aria-label="Shared" x-cloak>
                                @foreach ($shared as $s)
                                    @php($here = request()->routeIs($s['route']))
                                    <li>
                                        <a href="{{ $s['url'] }}" @class(['el-sb-subitem', 'el-on' => $here]) @if ($here) aria-current="true" @endif>
                                            @svg('heroicon-o-'.$s['icon'], ['aria-hidden' => 'true'])
                                            <span class="el-sb-label">{{ $s['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (Upload::canAccess())
                        @include('filament.shell.sidebar-item', ['label' => 'Upload', 'url' => Upload::getUrl(), 'icon' => 'arrow-up-tray', 'active' => request()->routeIs('filament.app.pages.upload'),
                            'title' => 'Voice notes, audio or video files. You can also drop them on the Library.'])
                    @endif
                @else
                    {{-- The admin panel: its own groups (Customers, Plans, Usage, System) in the same frame. --}}
                    @foreach (filament()->getNavigation() as $group)
                        <div class="el-sb-group el-sb-admin-group">
                            @if (filled($group->getLabel()))
                                <p class="el-sb-heading el-sb-label">{{ $group->getLabel() }}</p>
                            @endif
                            @foreach ($group->getItems() as $item)
                                @include('filament.shell.sidebar-item', [
                                    'label' => (string) $item->getLabel(), 'url' => (string) $item->getUrl(), 'active' => $item->isActive(),
                                    'heroicon' => $item->isActive() ? ($item->getActiveIcon() ?? $item->getIcon()) : $item->getIcon(), 'badge' => $item->getBadge(),
                                ])
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </nav>

            <div class="el-sb-footer">
                <button type="button" class="el-sb-item" x-on:click="$dispatch('el-help')" x-bind:title="rail() ? 'Help & shortcuts (?)' : null">
                    @svg('heroicon-o-question-mark-circle', ['class' => 'el-sb-icon', 'aria-hidden' => 'true'])
                    <span class="el-sb-label">Help &amp; shortcuts</span>
                    <kbd class="el-kbd el-sb-kbd" aria-hidden="true">?</kbd>
                </button>
                @php($onAccount = request()->routeIs('filament.app.auth.profile'))
                <a href="{{ $profileUrl }}" @class(['el-sb-item', 'el-on' => $onAccount]) @if ($onAccount) aria-current="page" @endif x-bind:title="rail() ? 'Account' : null">
                    @svg($onAccount ? 'heroicon-s-user-circle' : 'heroicon-o-user-circle', ['class' => 'el-sb-icon', 'aria-hidden' => 'true'])
                    <span class="el-sb-label">Account</span>
                </a>
                <button type="button" class="el-sb-item el-sb-collapse" x-on:click="toggle()" x-bind:aria-expanded="rail() ? 'false' : 'true'"
                    x-bind:aria-label="rail() ? 'Expand the sidebar' : 'Collapse the sidebar'" x-bind:title="rail() ? 'Expand the sidebar ([)' : null">
                    @svg('heroicon-o-view-columns', ['class' => 'el-sb-icon', 'aria-hidden' => 'true'])
                    <span class="el-sb-label">Collapse sidebar</span>
                    <kbd class="el-kbd el-sb-kbd" aria-hidden="true">[</kbd>
                </button>
            </div>

            <div class="el-sb-resize" role="separator" aria-orientation="vertical" aria-label="Resize the sidebar" tabindex="0" x-show="! rail()"
                aria-valuemin="{{ Shell::WIDTH_MIN }}" aria-valuemax="{{ Shell::WIDTH_MAX }}" x-bind:aria-valuenow="width"
                title="Drag to resize. Double-click for the usual width."
                x-on:pointerdown="startResize($event)" x-on:dblclick="setWidth(usual, true)" x-on:keydown="resizeKey($event)"></div>
        </div>
    </div>
    {{-- format-ignore-end --}}

    <x-filament-actions::modals />
</div>
