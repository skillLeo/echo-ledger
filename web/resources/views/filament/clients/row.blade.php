{{-- One client, as the phone lists it: initials (or its emoji) on its colour, the name, then its recordings. --}}
@php
    $client = $getRecord();
    $count = (int) ($client->capture_sessions_count ?? 0);
    $last = $client->capture_sessions_max_started_at ?? null;
    $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
    $initials = filled($client->avatar) ? $client->avatar : \App\Filament\Support\InitialsAvatarProvider::initials($client->name);
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $client->color) === 1 ? $client->color : '#3E63DD';
    $line = $count === 0 ? 'No recordings yet'
        : ($count === 1 ? '1 recording' : $count.' recordings').($last ? ' · Last call '.\Illuminate\Support\Carbon::parse($last, 'UTC')->setTimezone($zone)->format('M j') : '');
@endphp
<div class="el-row">
    <span class="el-avatar" style="--tile: {{ $color }}" aria-hidden="true">{{ $initials }}</span>
    <span class="el-row-main">
        <span class="el-row-title">{{ $client->name }}</span>
        <span class="el-row-sub"><span class="el-row-meta">{{ $line }}</span></span>
    </span>
</div>
