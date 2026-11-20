{{--
    The v2.0 top bar, beside the sidebar: the menu button on phones and tablets, the workspace, search and commands
    (Ctrl/⌘+K), then + (Upload recording, New project, Request link), the bell (only what needs you) and the avatar menu (Settings lives there on phones, as in the apps).
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
        @endif

        <span class="v2-tb-spacer" aria-hidden="true"></span>

        @if (filament()->auth()->check())
            @include('filament.v2.create-menu')
            @if (filament()->hasDatabaseNotifications())
                @livewire(filament()->getDatabaseNotificationsLivewireComponent(), ['lazy' => filament()->hasLazyLoadedDatabaseNotifications()])
            @endif
            @include('filament.v2.user-menu')
        @endif
    </nav>

    <x-filament-actions::modals />
</div>
