{{-- One project, as the phone lists it: initials (or its emoji) on its colour, the name, its subfolders and recordings. --}}
@php
    $client = $getRecord();
    $count = (int) ($client->capture_sessions_count ?? 0);
    $children = (int) ($client->children_count ?? 0);
    $last = $client->capture_sessions_max_started_at ?? null;
    $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
    $initials = filled($client->avatar) ? $client->avatar : \App\Filament\Support\InitialsAvatarProvider::initials($client->name);
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $client->color) === 1 ? $client->color : '#3E63DD';
    $line = collect([
        $children > 0 ? ($children === 1 ? '1 subfolder' : $children.' subfolders') : null,
        $count === 0 ? ($children === 0 ? 'No recordings yet' : null) : ($count === 1 ? '1 recording' : $count.' recordings'),
        $count > 0 && $last ? 'Last call '.\Illuminate\Support\Carbon::parse($last, 'UTC')->setTimezone($zone)->format('M j') : null,
    ])->filter()->implode(' · ');
    // A search lists every level: say which folder a subfolder is in.
    $parent = $client->relationLoaded('parent') ? $client->parent?->name : null;
@endphp
<div class="el-row">
    <span class="el-avatar" style="--tile: {{ $color }}" aria-hidden="true">{{ $initials }}</span>
    <span class="el-row-main">
        <span class="el-row-title">{{ $client->name }}</span>
        <span class="el-row-sub"><span class="el-row-meta">@if ($parent)In {{ $parent }} · @endif{{ $line }}</span></span>
    </span>
</div>
