{{--
    The phone's bottom bar on narrow screens: Library, Projects, Upload, Activity, and More (the full sidebar).
    Hidden from 1024 px, where the sidebar is always there.
--}}
@auth
    @php
        // Since v1.5 the small console (members and viewers) has the library and Shared with me only.
        $items = \App\Services\Workspaces\ConsoleAccess::full() ? [
            ['label' => 'Library', 'url' => \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('index'), 'route' => 'filament.admin.resources.sessions.*', 'icon' => 'film'],
            ['label' => 'Projects', 'url' => \App\Filament\Resources\Clients\ClientResource::getUrl('index'), 'route' => 'filament.admin.resources.clients.*', 'icon' => 'folder'],
            ['label' => 'Upload', 'url' => \App\Filament\Pages\Upload::getUrl(), 'route' => 'filament.admin.pages.upload', 'icon' => 'arrow-up-tray'],
            ['label' => 'Activity', 'url' => \App\Filament\Pages\Activity::getUrl(), 'route' => 'filament.admin.pages.activity', 'icon' => 'bell', 'badge' => \App\Filament\Pages\Activity::getNavigationBadge()],
        ] : (\App\Services\Workspaces\ConsoleAccess::open() ? [
            ['label' => 'Library', 'url' => \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('index'), 'route' => 'filament.admin.resources.sessions.*', 'icon' => 'film'],
            ['label' => 'Shared', 'url' => \App\Filament\Pages\SharedWithMe::getUrl(), 'route' => 'filament.admin.pages.shared-with-me', 'icon' => 'users'],
        ] : []);
    @endphp
    <nav class="el-bottom-nav" aria-label="Main">
        @foreach ($items as $item)
            @php($active = request()->routeIs($item['route']))
            <a href="{{ $item['url'] }}" @if ($active) aria-current="page" @endif>
                <span class="el-bottom-nav-icon">@svg(($active ? 'heroicon-s-' : 'heroicon-o-').$item['icon'], ['aria-hidden' => 'true'])</span>
                {{ $item['label'] }}
                @if (filled($item['badge'] ?? null))
                    <span class="el-bottom-nav-badge"><span class="el-sr">, unread: </span>{{ $item['badge'] }}</span>
                @endif
            </a>
        @endforeach
        <button type="button" x-data x-on:click="$store.sidebar.open()" aria-label="More pages">
            <span class="el-bottom-nav-icon">@svg('heroicon-o-bars-3', ['aria-hidden' => 'true'])</span>
            More
        </button>
    </nav>
@endauth
