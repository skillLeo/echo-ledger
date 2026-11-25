{{--
    The console's three levels of navigation (docs/PLAN_NAV_3_LEVELS.md, the desktop's layout): the RAIL (the logo, which
    opens Home; ● Record; Library, Projects, Live, Ask and Activity; at its foot Setup & health, Help, Settings and the
    avatar), then the PANEL of the place you are in (its views and collections; ⊟ or ⌘\ hides it, and it is remembered
    for each place), then the page with its own tabs. Under the flyout width (--el-breakpoint-flyout) the panel folds
    away and a rail icon opens it over the page, closing once something is chosen; on a phone the whole frame is the
    menu's drawer. Right to left the rail and the panel are on the right. It keeps Filament's sidebar contract
    (filamentSidebar, $store.sidebar: open = the panel shown beside the page). The keys and the memory are
    elV2Frame's (filament.v2.frame-script, resources/js/frame-core.cjs).
--}}
@php
    use App\Filament\Support\ConsoleV2Nav;
    use App\Filament\Support\NavFrame;
    use Illuminate\Support\Number;

    $rail = NavFrame::rail();
    $here = $rail['here'];
    $places = collect($rail['places'])->keyBy('key');
    $upload = ConsoleV2Nav::upload();
    $mac = str_contains((string) request()->userAgent(), 'Mac OS X');
    $panels = collect(['library', 'projects', 'live', 'ask', 'activity', 'settings'])->filter(fn (string $key): bool => $places->has($key) && $places[$key]['url'] !== null)->values()->all();
    $config = ['place' => $here, 'panels' => $panels, 'homes' => $places->map(fn (array $p): ?string => $p['url'])->all(), 'scope' => NavFrame::memoryScope()];
    // FIX-06: the rail is one Tab stop before any script runs: the place you are on (aria-current), else the logo; the
    // arrows move it (resources/js/a11y-core.cjs, which keeps the same stop after each redraw).
    $railStop = match (true) {
        $here !== null && ($places[$here]['url'] ?? null) !== null => $here,
        (bool) ($rail['setup']['active'] ?? false) => 'setup',
        default => 'home',
    };
@endphp
<div>
    {{-- format-ignore-start --}}
    <div
        x-data="filamentSidebar({ hasTopNavigation: false, isSidebarCollapsibleOnDesktop: true, isSidebarFullyCollapsibleOnDesktop: false })"
        x-cloak
        x-bind:class="{ 'fi-sidebar-open': $store.sidebar.isOpen }"
        x-bind:inert="isHidden"
        x-on:keydown.escape.document="handleEscape($event)"
        id="fi-main-sidebar"
        class="fi-sidebar fi-main-sidebar v2-frame"
        data-style="{{ ConsoleV2Nav::style() }}"
        data-place="{{ $here ?? 'home' }}"
    >
        <div class="v2-frame-inner" x-data="elV2Frame(@js($config))" x-bind:class="{ 'is-flyout': flyout !== null, 'is-narrow': narrow }"
            x-on:keydown.escape.window="if (flyout !== null && ! inDialog($event.target)) closeFlyout('escape')"
            x-on:click.outside="if (! inDialog($event.target)) closeFlyout('outside')"
            x-on:focusin.window="if (flyout !== null && ! $el.contains($event.target) && ! inDialog($event.target)) closeFlyout('outside')"
            x-on:blur.window="if (flyout !== null && document.activeElement?.tagName === 'IFRAME') closeFlyout('outside')"
            x-on:el-nav-show-panel.window="showPanel($event)">
            <nav class="v2-rail" aria-label="{{ __('Places') }}">
                <a href="{{ NavFrame::homeUrl() }}" @class(['v2-rail-logo', 'is-on' => $here === null && request()->routeIs('filament.app.pages.dashboard')])
                    title="{{ __('Home') }}" data-module="home" data-initial="{{ mb_substr(\App\Support\Brand::name(), 0, 1) }}" tabindex="{{ $railStop === 'home' ? '0' : '-1' }}">
                    {{-- The brand's first letter is drawn (::before); the link's name is Home. --}}
                    <span class="v2-sr">{{ __('Home') }}</span>
                </a>

                @if ($upload !== null)
                    {{-- ● Record: a voice note recorded here at once; its menu also imports audio files (on the Library its
                         file picker). The web records nothing in the background, so there is no call recording switch. --}}
                    <div class="v2-rail-record">
                        <a href="{{ $upload['record'] }}" @class(['v2-rail-rec', 'is-on' => $upload['active']]) data-sidebar-upload data-record
                            title="{{ __('Record a voice note') }}" tabindex="-1">
                            <span class="v2-rail-rec-dot" aria-hidden="true"><x-el.v2.icon name="record" /></span>
                            <span class="v2-rail-label">{{ $upload['label'] }}</span>
                        </a>
                        <x-filament::dropdown placement="right-start" wire:key="v2-rail-record-menu">
                            <x-slot name="trigger">
                                <button type="button" class="v2-rail-rec-more" aria-label="{{ __('Record options') }}" data-record-menu tabindex="-1">
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

                <ul class="v2-rail-list">
                    @foreach ($rail['places'] as $place)
                        @continue($place['key'] === 'settings')
                        <li>
                            @include('filament.v2.frame-rail-item', ['place' => $place, 'mac' => $mac])
                        </li>
                    @endforeach
                </ul>

                <div class="v2-rail-foot">
                    @if ($rail['setup'] !== null)
                        {{-- "Setup" under the icon (the rail's names are short); its full name is Setup & health. --}}
                        <a href="{{ $rail['setup']['url'] }}" @class(['v2-rail-item', 'is-on' => $rail['setup']['active']]) @if ($rail['setup']['active']) aria-current="page" @endif
                            title="{{ $rail['setup']['label'] }}" aria-label="{{ $rail['setup']['label'] }}{{ $rail['setup']['dot'] ? ', '.__('something to set up') : '' }}" data-module="setup"
                            tabindex="{{ $railStop === 'setup' ? '0' : '-1' }}">
                            <span class="v2-rail-icon"><x-el.v2.icon name="device" />@if ($rail['setup']['dot'])<span class="v2-rail-dot" aria-hidden="true"></span>@endif</span>
                            <span class="v2-rail-label" aria-hidden="true">{{ __('Setup') }}</span>
                        </a>
                    @endif
                    <button type="button" class="v2-rail-item" x-on:click="$dispatch('el-help')" title="{{ __('Help and shortcuts') }}" data-module="help" tabindex="-1">
                        <span class="v2-rail-icon"><x-el.v2.icon name="help" /></span>
                        <span class="v2-rail-label">{{ __('Help') }}</span>
                    </button>
                    @if ($places->has('settings'))
                        @include('filament.v2.frame-rail-item', ['place' => $places['settings'], 'mac' => $mac])
                    @endif
                    <div class="v2-rail-avatar">
                        @include('filament.v2.user-menu', ['placement' => 'right-end', 'inRail' => true])
                    </div>
                </div>
            </nav>

            @if ($panels !== [])
                <div class="v2-panels" id="v2-panels">
                    @foreach ($panels as $key)
                        @php $place = $places[$key]; @endphp
                        {{-- The place you are in shows at once; the others wait for the flyout (hidden until chosen). --}}
                        <section class="v2-panel" data-panel="{{ $key }}" aria-label="{{ $place['label'] }}" @if ($key !== $here) hidden @endif
                            x-bind:hidden="shown() !== @js($key)" id="v2-panel-{{ $key }}">
                            <header class="v2-panel-head">
                                <h2 class="v2-panel-title"><a href="{{ $place['url'] }}" data-panel-home="{{ $key }}">{{ $place['label'] }}</a></h2>
                                {{-- As a flyout it closes (focus back on what opened it); beside the page it hides. --}}
                                <button type="button" class="v2-icon-btn v2-panel-hide" x-on:click="togglePanel()" data-panel-toggle
                                    aria-label="{{ __('Hide the panel') }}" title="{{ __('Hide the panel') }} ({{ $mac ? '⌘\\' : 'Ctrl \\' }})"
                                    x-bind:aria-label="flyout !== null ? @js(__('Close the panel')) : @js(__('Hide the panel'))">
                                    <x-el.v2.icon name="columns" />
                                </button>
                            </header>
                            <div class="v2-panel-body" x-on:click="if ($event.target.closest('a[href]')) closeFlyout('chosen')">
                                @include('filament.v2.frame-panel-'.$key)
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    {{-- format-ignore-end --}}
    @include('filament.v2.object-menus', ['menus' => $this->objectMenus()])

    <x-filament-actions::modals />
</div>
