{{--
    The v2.0 sidebar (docs/PLAN_V2_PARITY.md section 2, docs/v2/IA.md): the brand, "● Record ▾" at the top (since
    2.0.0-p, docs/PLAN_LIBRARY_FILTERS.md: Record a voice note, and Import audio files in its menu), then Home, Library
    (its places, My views and Labels: filament.v2.sidebar-library), Projects (the first few with their counts), Live and
    Ask; at the foot Trash, Activity with what needs you, Settings, Help and Collapse. The web has no sync of its own
    (it reads the server), so no "Synced" line. Its edge
    resizes it (drag, or the arrow keys), and its look is the person's Sidebar style (Light, Dark, Soft grey). It keeps
    Filament's sidebar contract (filamentSidebar, $store.sidebar), so on phones it is the drawer the menu button opens;
    folded on a computer it is a rail of icons with their names as tooltips.
--}}
@php
    use App\Filament\Support\ConsoleV2Nav;
    use App\Filament\Support\Shell;

    $modules = collect(ConsoleV2Nav::modules())->keyBy('key');
    $nav = $modules->except('settings')->values()->all();
    $settings = $modules->get('settings');
    // Since 2.0.0-p the new Library (ui_v2.library) brings its own places, My views, Labels, Trash at the foot and
    // ● Record ▾; with the new frame alone the Library's level 2 and Upload recording stay as they were.
    $newLibrary = \App\Filament\Support\ConsoleV2::library();
    $library = $newLibrary ? [] : ConsoleV2Nav::libraryPlaces();
    $trash = $newLibrary ? ConsoleV2Nav::trash() : null;
    $projects = ConsoleV2Nav::projects();
    $upload = ConsoleV2Nav::upload();
    $activity = ConsoleV2Nav::activity();
    $prefs = Shell::preferences();
    $config = [
        'width' => $prefs['width'], 'min' => Shell::WIDTH_MIN, 'max' => Shell::WIDTH_MAX, 'usual' => Shell::WIDTH_DEFAULT,
        'collapsed' => $prefs['collapsed'], 'open' => $prefs['open'], 'pages' => [], 'place' => null,
    ];
@endphp
<div>
    {{-- format-ignore-start --}}
    <div
        x-data="filamentSidebar({ hasTopNavigation: false, isSidebarCollapsibleOnDesktop: true, isSidebarFullyCollapsibleOnDesktop: false })"
        x-cloak
        x-bind:class="{ 'fi-sidebar-open': $store.sidebar.isOpen, 'v2-rail': ! $store.sidebar.isOpen && window.matchMedia('(min-width: 1024px)').matches }"
        x-bind:inert="isHidden"
        x-effect="focusInitialNavigation()"
        x-on:keydown.tab="handleTab($event)"
        x-on:keydown.escape.document="handleEscape($event)"
        id="fi-main-sidebar"
        class="fi-sidebar fi-main-sidebar v2-sb"
        data-style="{{ ConsoleV2Nav::style() }}"
    >
        <div class="v2-sb-inner" x-data="elV2Sidebar(@js($config))">
            <header class="v2-sb-brand">
                <a href="{{ filament()->getHomeUrl() }}" class="v2-sb-logo" aria-label="{{ __(':app home', ['app' => \App\Support\Brand::name()]) }}">
                    {{-- The name alone, as the desktop app's sidebar shows it. --}}
                    {{ \App\Support\Brand::name() }}
                </a>
                <button type="button" class="v2-icon-btn v2-sb-close" x-on:click="$store.sidebar.close()" aria-label="{{ __('Close the menu') }}">
                    <x-el.v2.icon name="close" />
                </button>
            </header>

            @if ($upload !== null && ! $newLibrary)
                {{-- The web's Record (parity plan 2): a file from the computer, or a voice note recorded here. --}}
                <a href="{{ $upload['url'] }}" @class(['v2-sb-record', 'is-on' => $upload['active']]) data-sidebar-upload
                    x-bind:title="$el.closest('.v2-rail') ? @js(__('Upload recording')) : null">
                    <x-el.v2.icon name="record" />
                    <span class="v2-sb-label">{{ __('Upload recording') }}</span>
                </a>
            @elseif ($upload !== null)
                {{-- ● Record ▾ (2.0.0-p): a voice note recorded here; the menu also imports audio files (on the Library its
                     file picker, elsewhere the Upload page), and files dropped anywhere on the Library are imported too. --}}
                <div class="v2-sb-split">
                    <a href="{{ $upload['record'] }}" @class(['v2-sb-record', 'is-on' => $upload['active']]) data-sidebar-upload data-record
                        x-bind:title="$el.closest('.v2-rail') ? @js($upload['label']) : null">
                        <x-el.v2.icon name="record" />
                        <span class="v2-sb-label">{{ $upload['label'] }}</span>
                    </a>
                    <x-filament::dropdown placement="bottom-end" wire:key="v2-sb-record-menu">
                        <x-slot name="trigger">
                            <button type="button" class="v2-sb-record-more" aria-label="{{ __('Record options') }}" data-record-menu>
                                <x-el.v2.icon name="chevron-down" />
                            </button>
                        </x-slot>
                        <x-filament::dropdown.list>
                            <x-filament::dropdown.list.item tag="a" :href="$upload['record']" icon="heroicon-o-microphone" data-record-now>
                                {{ __('Record a voice note') }}
                            </x-filament::dropdown.list.item>
                            <x-filament::dropdown.list.item tag="a" :href="$upload['url']" icon="heroicon-o-arrow-up-tray" data-import
                                x-on:click="if (document.querySelector('.v2-library')) { $event.preventDefault(); close(); $dispatch('el-upload-pick') }">
                                {{ __('Import audio files') }}
                            </x-filament::dropdown.list.item>
                        </x-filament::dropdown.list>
                    </x-filament::dropdown>
                </div>
            @endif

            <nav class="v2-sb-nav" aria-label="{{ __('Main') }}">
                <ul class="v2-sb-list">
                    @foreach ($nav as $item)
                        <li>
                            @if ($item['url'] !== null)
                                <a href="{{ $item['url'] }}" @class(['v2-sb-item', 'is-on' => $item['active']]) @if ($item['active']) aria-current="page" @endif
                                    x-bind:title="$el.closest('.v2-rail') ? @js($item['label']) : null" data-module="{{ $item['key'] }}">
                                    <x-el.v2.icon :name="$item['icon']" :filled="$item['active']" />
                                    <span class="v2-sb-label">{{ $item['label'] }}</span>
                                </a>
                            @else
                                {{-- A module this console can't open: shown with the reason, never hidden (IA level rules). --}}
                                <span class="v2-sb-item is-off" aria-disabled="true" title="{{ $item['reason'] }}" data-module="{{ $item['key'] }}">
                                    <x-el.v2.icon :name="$item['icon']" />
                                    <span class="v2-sb-label">{{ $item['label'] }}</span>
                                </span>
                            @endif
                            @if ($item['key'] === 'library' && $item['url'] !== null && $newLibrary)
                                @include('filament.v2.sidebar-library')
                            @elseif ($item['key'] === 'library' && $library !== [])
                                <ul class="v2-sb-sub" aria-label="{{ __('Library') }}">
                                    @foreach ($library as $place)
                                        <li>
                                            <a href="{{ $place['url'] }}" @class(['v2-sb-subitem', 'is-on' => $place['active']]) @if ($place['active']) aria-current="page" @endif data-place="{{ $place['key'] }}">
                                                <span class="v2-sb-label">{{ $place['label'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($item['key'] === 'projects' && $item['url'] !== null && $projects !== [])
                                <ul class="v2-sb-sub" aria-label="{{ __('Projects') }}">
                                    @foreach ($projects as $project)
                                        <li>
                                            <a href="{{ $project['url'] }}" @class(['v2-sb-subitem', 'is-child' => $project['depth'] > 0, 'is-on' => $project['active']])
                                                @if ($project['active']) aria-current="page" @endif data-project="{{ $project['id'] }}">
                                                <span class="v2-sb-label"><bdi>{{ $project['name'] }}</bdi></span>
                                                @if ($project['count'] > 0)
                                                    <span class="v2-sb-subcount">{{ \Illuminate\Support\Number::format($project['count']) }}</span>
                                                @endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="v2-sb-foot">
                @if ($trash !== null)
                    <a href="{{ $trash['url'] }}" @class(['v2-sb-item', 'is-on' => $trash['active']]) @if ($trash['active']) aria-current="page" @endif
                        x-bind:title="$el.closest('.v2-rail') ? @js($trash['label']) : null" data-place="trash">
                        <x-el.v2.icon name="trash" :filled="$trash['active']" />
                        <span class="v2-sb-label">{{ $trash['label'] }}</span>
                    </a>
                @endif
                @if ($activity !== null)
                    <a href="{{ $activity['url'] }}" @class(['v2-sb-item', 'is-on' => $activity['active']]) @if ($activity['active']) aria-current="page" @endif
                        x-bind:title="$el.closest('.v2-rail') ? @js($activity['label']) : null" data-module="activity">
                        <x-el.v2.icon name="bell" :filled="$activity['active']" />
                        <span class="v2-sb-label">{{ $activity['label'] }}</span>
                        @if ($activity['badge'] !== null)
                            <span class="v2-sb-count is-alert"><span class="v2-sr">{{ __(', needs you:') }} </span>{{ $activity['badge'] }}</span>
                        @endif
                    </a>
                @endif
                @if ($settings !== null)
                    <a href="{{ $settings['url'] }}" @class(['v2-sb-item', 'is-on' => $settings['active']]) @if ($settings['active']) aria-current="page" @endif
                        x-bind:title="$el.closest('.v2-rail') ? @js($settings['label']) : null" data-module="settings">
                        <x-el.v2.icon name="settings" :filled="$settings['active']" />
                        <span class="v2-sb-label">{{ $settings['label'] }}</span>
                    </a>
                @endif
                <button type="button" class="v2-sb-item" x-on:click="$dispatch('el-help')" x-bind:title="$el.closest('.v2-rail') ? @js(__('Help and shortcuts')) : null">
                    <x-el.v2.icon name="help" />
                    <span class="v2-sb-label">{{ __('Help and shortcuts') }}</span>
                </button>
                <button type="button" class="v2-sb-item v2-sb-collapse" x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
                    x-bind:aria-label="$store.sidebar.isOpen ? @js(__('Collapse the sidebar')) : @js(__('Expand the sidebar'))"
                    x-bind:aria-expanded="$store.sidebar.isOpen ? 'true' : 'false'">
                    <x-el.v2.icon name="chevron-start" />
                    <span class="v2-sb-label">{{ __('Collapse') }}</span>
                </button>
            </div>

            {{-- The edge resizes the sidebar: drag it, or the arrow keys (Shift for bigger steps); a double-click is the usual width. --}}
            <div class="v2-sb-resize" role="separator" aria-orientation="vertical" aria-label="{{ __('Resize the sidebar') }}" tabindex="0" x-show="! rail()"
                aria-valuemin="{{ Shell::WIDTH_MIN }}" aria-valuemax="{{ Shell::WIDTH_MAX }}" aria-valuenow="{{ $prefs['width'] }}" x-bind:aria-valuenow="width"
                title="{{ __('Drag to resize. Double-click for the usual width.') }}"
                x-on:pointerdown="startResize($event)" x-on:dblclick="setWidth(usual, true)" x-on:keydown="resizeKey($event)"></div>
        </div>
    </div>
    {{-- format-ignore-end --}}

    <x-filament-actions::modals />
</div>
