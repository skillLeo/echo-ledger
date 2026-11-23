{{--
    One place in the rail: its icon over its short name, the badge (the Library's needs-you count, Activity's), and the
    shortcut in the tooltip (⌘1…⌘6, Ctrl off a Mac). The selected one is a soft accent tint. A click on a place you are
    not in opens where you left it; on the one you are in it starts that place over (elV2Frame.railClick). A place
    this console can't open shows with its reason, never hidden.
--}}
@php
    $tip = $place['label'].' ('.($mac ? '⌘' : 'Ctrl ').$place['shortcut'].')';
@endphp
@if ($place['url'] !== null)
    <a href="{{ $place['url'] }}" @class(['v2-rail-item', 'is-on' => $place['active']]) @if ($place['active']) aria-current="page" @endif
        title="{{ $tip }}" data-module="{{ $place['key'] }}" data-rail="{{ $place['key'] }}" data-rail-shortcut="{{ $place['shortcut'] }}"
        x-on:click="railClick($event, @js($place['key']))">
        <span class="v2-rail-icon"><x-el.v2.icon :name="$place['icon']" :filled="$place['active']" /></span>
        <span class="v2-rail-label">{{ $place['label'] }}</span>
        @if ($place['badge'] !== null)
            {{-- After the name, so it reads "Activity, needs you: 4"; drawn on the icon's corner. --}}
            <span class="v2-rail-badge"><span class="v2-sr">{{ __(', needs you:') }} </span>{{ $place['badge'] }}</span>
        @endif
    </a>
@else
    <span class="v2-rail-item is-off" aria-disabled="true" title="{{ $place['reason'] }}" data-module="{{ $place['key'] }}">
        <span class="v2-rail-icon"><x-el.v2.icon :name="$place['icon']" /></span>
        <span class="v2-rail-label">{{ $place['label'] }}</span>
    </span>
@endif
