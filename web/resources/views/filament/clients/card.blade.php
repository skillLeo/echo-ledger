{{--
    One project as a card on the Projects overview (docs/PLAN_NAV_3_LEVELS.md section 3, with the three levels): its
    initials (or emoji) on its colour, its name, how many recordings and subfolders it holds, its last activity, and
    whether it is pinned. The tree itself is the panel's.
--}}
@php
    $client = $getRecord();
    $count = (int) ($client->capture_sessions_count ?? 0);
    $children = (int) ($client->children_count ?? 0);
    $last = $client->capture_sessions_max_started_at ?? null;
    $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
    $initials = filled($client->avatar) ? $client->avatar : \App\Filament\Support\InitialsAvatarProvider::initials($client->name);
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $client->color) === 1 ? $client->color : '#3E63DD';
    $counts = collect([
        $count === 1 ? __(':n recording', ['n' => 1]) : __(':n recordings', ['n' => \Illuminate\Support\Number::format($count)]),
        $children > 0 ? ($children === 1 ? __(':n subfolder', ['n' => 1]) : __(':n subfolders', ['n' => $children])) : null,
    ])->filter()->implode(' · ');
    $activity = $count > 0 && $last
        ? __('Last activity :date', ['date' => \App\Filament\Support\ConsoleDates::day(\Illuminate\Support\Carbon::parse($last, 'UTC')->setTimezone($zone))])
        : __('No recordings yet');
    // A search or the Pinned and Recent views reach every level: say which folder a subfolder is in.
    $parent = $client->relationLoaded('parent') ? $client->parent?->name : null;
@endphp
<div class="v2-project-card" data-project-card="{{ $client->id }}">
    <span class="el-avatar" style="--tile: {{ $color }}" aria-hidden="true">{{ $initials }}</span>
    <span class="v2-project-card-main">
        <span class="v2-project-card-name" dir="auto"><bdi>{{ $client->name }}</bdi></span>
        @if ($parent)
            <span class="v2-project-card-meta">{{ __('In :folder', ['folder' => $parent]) }}</span>
        @endif
        <span class="v2-project-card-meta" data-project-counts>{{ $counts }}</span>
        <span class="v2-project-card-meta" data-project-last>{{ $activity }}</span>
    </span>
    @if ($client->pinned)
        <span class="v2-project-card-pin" data-project-pinned>{{ __('Pinned') }}</span>
    @endif
</div>
