{{--
    The v2.0 sidebar (docs/v2/IA.md "Navigation per platform": Home, Library, Live, Ask, Settings), the desktop app's
    frame: the brand, the five modules, and under Library its level 2 (All recordings, Projects, Shared, Trash). Help
    and Collapse at the bottom. It keeps Filament's sidebar contract (filamentSidebar, $store.sidebar), so on phones it
    is the drawer the menu button opens; folded on a computer it is a rail of icons with their names as tooltips.
--}}
@php
    use App\Filament\Support\ConsoleV2Nav;

    $nav = ConsoleV2Nav::modules();
    $library = ConsoleV2Nav::libraryPlaces();
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
    >
        <div class="v2-sb-inner">
            <header class="v2-sb-brand">
                <a href="{{ filament()->getHomeUrl() }}" class="v2-sb-logo" aria-label="{{ __(':app home', ['app' => \App\Support\Brand::name()]) }}">
                    {{-- The name alone, as the desktop app's sidebar shows it. --}}
                    {{ \App\Support\Brand::name() }}
                </a>
                <button type="button" class="v2-icon-btn v2-sb-close" x-on:click="$store.sidebar.close()" aria-label="{{ __('Close the menu') }}">
                    <x-el.v2.icon name="close" />
                </button>
            </header>

            <nav class="v2-sb-nav" aria-label="{{ __('Main') }}">
                <ul class="v2-sb-list">
                    @foreach ($nav as $item)
                        <li>
                            @if ($item['url'] !== null)
                                <a href="{{ $item['url'] }}" @class(['v2-sb-item', 'is-on' => $item['active']]) @if ($item['active']) aria-current="page" @endif
                                    x-bind:title="$el.closest('.v2-rail') ? @js($item['label']) : null" data-module="{{ $item['key'] }}">
                                    <x-el.v2.icon :name="$item['icon']" :filled="$item['active']" />
                                    <span class="v2-sb-label">{{ $item['label'] }}</span>
                                    @if (filled($item['badge'] ?? null))
                                        <span class="v2-sb-count"><span class="v2-sr">{{ __(', needs you:') }} </span>{{ $item['badge'] }}</span>
                                    @endif
                                </a>
                            @else
                                {{-- A module this console can't open: shown with the reason, never hidden (IA level rules). --}}
                                <span class="v2-sb-item is-off" aria-disabled="true" title="{{ $item['reason'] }}" data-module="{{ $item['key'] }}">
                                    <x-el.v2.icon :name="$item['icon']" />
                                    <span class="v2-sb-label">{{ $item['label'] }}</span>
                                </span>
                            @endif
                            @if ($item['key'] === 'library' && $library !== [])
                                <ul class="v2-sb-sub" aria-label="{{ __('Library') }}">
                                    @foreach ($library as $place)
                                        <li>
                                            <a href="{{ $place['url'] }}" @class(['v2-sb-subitem', 'is-on' => $place['active']]) @if ($place['active']) aria-current="page" @endif>
                                                <span class="v2-sb-label">{{ $place['label'] }}</span>
                                                @if (($place['count'] ?? 0) > 0)
                                                    <span class="v2-sb-subcount">{{ \Illuminate\Support\Number::format($place['count']) }}</span>
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
        </div>
    </div>
    {{-- format-ignore-end --}}

    <x-filament-actions::modals />
</div>
