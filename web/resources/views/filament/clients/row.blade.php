{{-- W-209: a name or value inside a translated sentence is isolated in right to left, so the sentence keeps the page's direction (D4 §4). --}}
@php
    $iso = fn (mixed $value): string => \App\Filament\Support\ConsoleV2::rtl() ? "\u{2068}".$value."\u{2069}" : (string) $value;
@endphp
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
        $children > 0 ? ($children === 1 ? __(':n subfolder', ['n' => 1]) : __(':n subfolders', ['n' => $children])) : null,
        $count === 0 ? ($children === 0 ? __('No recordings yet') : null) : ($count === 1 ? __(':n recording', ['n' => 1]) : __(':n recordings', ['n' => $count])),
        $count > 0 && $last ? __('Last call :date', ['date' => \App\Filament\Support\ConsoleDates::day(\Illuminate\Support\Carbon::parse($last, 'UTC')->setTimezone($zone))]) : null,
    ])->filter()->implode(' · ');
    // A search lists every level: say which folder a subfolder is in.
    $parent = $client->relationLoaded('parent') ? $client->parent?->name : null;
@endphp
<div class="el-row">
    <span class="el-avatar" style="--tile: {{ $color }}" aria-hidden="true">{{ $initials }}</span>
    <span class="el-row-main">
        <span class="el-row-title">{{ $client->name }}</span>
        <span class="el-row-sub"><span class="el-row-meta">@if ($parent){{ __('In :folder', ['folder' => $iso($parent)]) }} · @endif{{ $line }}</span></span>
    </span>
</div>
