{{--
    The phone's bottom tabs (since v1.6, P4.6, on screens up to 600 px): Home, Library, Record, Activity, and More
    (the whole sidebar). Record opens the phone's voice recorder from the Upload page (the apps record calls). The
    small console (members and viewers) has Home, Library, Shared and More.
--}}
@auth
    @php
        $full = \App\Services\Workspaces\ConsoleAccess::full();
        $items = \App\Services\Workspaces\ConsoleAccess::open() ? array_values(array_filter([
            ['label' => 'Home', 'url' => \App\Filament\Pages\Dashboard::getUrl(), 'route' => 'filament.app.pages.dashboard', 'icon' => 'home'],
            ['label' => 'Library', 'url' => \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('index'), 'route' => 'filament.app.resources.sessions.*', 'icon' => 'film'],
            $full && ! \App\Services\Workspaces\ConsoleAccess::readOnly()
                ? ['label' => 'Record', 'url' => \App\Filament\Pages\Upload::getUrl(['record' => 1]), 'route' => 'filament.app.pages.upload', 'icon' => 'microphone']
                : null,
            $full
                ? ['label' => 'Activity', 'url' => \App\Filament\Pages\Activity::getUrl(), 'route' => 'filament.app.pages.activity', 'icon' => 'bell', 'badge' => \App\Filament\Pages\Activity::getNavigationBadge()]
                : ['label' => 'Shared', 'url' => \App\Filament\Pages\SharedWithMe::getUrl(), 'route' => 'filament.app.pages.shared-with-me', 'icon' => 'users'],
        ])) : [];
    @endphp
    <nav class="el-bottom-nav" aria-label="Main" style="--tabs: {{ count($items) + 1 }}">
        @foreach ($items as $item)
            @php($active = request()->routeIs($item['route']))
            <a href="{{ $item['url'] }}" @if ($active) aria-current="page" @endif>
                <span class="el-bottom-nav-icon">@svg(($active ? 'heroicon-s-' : 'heroicon-o-').$item['icon'], ['aria-hidden' => 'true'])</span>
                {{ $item['label'] }}
                @if (filled($item['badge'] ?? null))
                    <span class="el-bottom-nav-badge"><span class="el-sr">, needs you: </span>{{ $item['badge'] }}</span>
                @endif
            </a>
        @endforeach
        <button type="button" x-data x-on:click="$store.sidebar.open()" aria-label="More pages">
            <span class="el-bottom-nav-icon">@svg('heroicon-o-bars-3', ['aria-hidden' => 'true'])</span>
            More
        </button>
    </nav>
@endauth
