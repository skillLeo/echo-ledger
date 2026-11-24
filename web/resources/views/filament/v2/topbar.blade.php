{{--
    The v2.0 top bar, beside the sidebar: the menu button on phones and tablets, the workspace, search and commands
    (Ctrl/⌘+K), then + (Upload recording, New project, Request link), the bell (only what needs you) and the avatar menu (Settings lives there on phones, as in the apps).
    Decision 18 (docs/PLAN_V2_1_POLISH.md), as the desktop's title row: the search sits at the bar's end, 180 to 260 px;
    with less room the empty space before it goes first, then it shrinks to 180 px, then it is the ⌕ button, which opens
    it over the bar (filament.v2.header-fit).
--}}
@php
    use Filament\Livewire\GlobalSearch;
@endphp
<div class="fi-topbar-ctn v2-tb-ctn">
    <nav class="fi-topbar v2-tb" aria-label="{{ __('Top bar') }}">
        <button type="button" class="v2-icon-btn v2-tb-menu" x-data x-on:click="$store.sidebar.open($event.currentTarget)"
            aria-controls="fi-main-sidebar" x-bind:aria-expanded="$store.sidebar.isOpen ? 'true' : 'false'" aria-label="{{ __('Open the menu') }}">
            <x-el.v2.icon name="menu" />
        </button>

        @include('filament.shell.workspace-switcher')

        @if (filament()->isGlobalSearchEnabled())
            <div class="v2-tb-search">
                @livewire(GlobalSearch::class)
            </div>
        @else
            <span class="v2-tb-spacer" aria-hidden="true"></span>
        @endif

        @if (filament()->auth()->check())
            @include('filament.v2.create-menu')
            {{-- With the rail (docs/PLAN_NAV_3_LEVELS.md) Activity and the avatar are the rail's: never at two levels. --}}
            @unless (\App\Filament\Support\ConsoleV2::frame())
                @if (filament()->hasDatabaseNotifications())
                    @livewire(filament()->getDatabaseNotificationsLivewireComponent(), ['lazy' => filament()->hasLazyLoadedDatabaseNotifications()])
                @endif
                @include('filament.v2.user-menu')
            @endunless
        @endif
    </nav>
    {{-- Fitted before the first paint (filament.v2.header-fit). --}}
    <script>window.ElHeaderFit?.fit();</script>

    <x-filament-actions::modals />
</div>
