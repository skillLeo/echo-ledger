{{--
    The top bar (since v1.6, P4.3), to the right of the sidebar: the menu button on phones and tablets, the workspace,
    the search and commands field (Ctrl/⌘+K), "+" (Upload, New project, Request link), the bell (only what needs you),
    help (?) and the avatar menu (Upgrade or Plan & usage, Settings, the theme, Admin panel for staff, Sign out).
--}}
@php
    use App\Filament\Support\Shell;
    use Filament\Enums\UserMenuPosition;
    use Filament\Livewire\GlobalSearch;

    $inApp = Shell::inApp();
@endphp
<div class="fi-topbar-ctn el-tb-ctn">
    <nav class="fi-topbar el-tb" aria-label="Top bar">
        <button type="button" class="el-tb-icon el-tb-menu" x-data x-on:click="$store.sidebar.open($event.currentTarget)"
            aria-controls="fi-main-sidebar" x-bind:aria-expanded="$store.sidebar.isOpen ? 'true' : 'false'" aria-label="Open the menu">
            @svg('heroicon-o-bars-3', ['aria-hidden' => 'true'])
        </button>

        @if ($inApp)
            @include('filament.shell.workspace-switcher')
        @else
            <span class="el-tb-admin-label">Admin panel</span>
        @endif

        {{-- The admin panel finds its pages in the sidebar's Find a page and its customers on Customers. --}}
        @if ($inApp && filament()->isGlobalSearchEnabled())
            <div class="el-tb-search">
                @livewire(GlobalSearch::class)
            </div>
        @endif

        <span class="el-tb-spacer" aria-hidden="true"></span>

        @if ($inApp)
            @include('filament.shell.create-menu')
        @endif

        @if (filament()->auth()->check())
            @if (filament()->hasDatabaseNotifications())
                @livewire(filament()->getDatabaseNotificationsLivewireComponent(), ['lazy' => filament()->hasLazyLoadedDatabaseNotifications()])
            @endif

            <button type="button" class="el-tb-icon el-tb-help" x-data x-on:click="$dispatch('el-help')" aria-label="Help &amp; shortcuts" title="Help &amp; shortcuts (?)">
                @svg('heroicon-o-question-mark-circle', ['aria-hidden' => 'true'])
            </button>

            @if ($inApp)
                @include('filament.shell.user-menu')
            @else
                <x-filament-panels::user-menu :position="UserMenuPosition::Topbar" />
            @endif
        @endif
    </nav>

    <x-filament-actions::modals />
</div>
