{{--
    One v2.0 Library row: the source's glyph on a neutral circle, the title, then one status (only what needs you) and
    the second line (the source, the device, the project or caller); the star pins it to the top; at the end the time
    above the length. From 1024 px a plain click opens the recording beside the
    list, as in the classic Library (Ctrl, Cmd, Shift and middle clicks open its page).
--}}
@php($row = \App\Filament\Support\V2RecordingRow::for($getRecord()))
<div class="v2-lib-row" data-recording="{{ $row['id'] }}"
    x-on:click="if (window.matchMedia('(min-width: 1024px)').matches && ! ($event.ctrlKey || $event.metaKey || $event.shiftKey || $event.button !== 0) && typeof $wire.selectRecording === 'function') { $event.preventDefault(); $wire.selectRecording('{{ $row['id'] }}') }">
    <span class="v2-row-tile" title="{{ $row['source'] }}"><x-el.v2.icon :name="$row['icon']" /></span>
    <span class="v2-row-main">
        <span class="v2-row-title" dir="auto">{{ $row['title'] }}</span>
        <span class="v2-lib-line">
            @if ($row['status'] !== null)
                <span @class(['v2-status', 'is-'.$row['status']['tone']])>
                    <x-el.v2.icon :name="$row['status']['icon']" />{{ $row['status']['label'] }}
                </span>
            @endif
            <span class="v2-row-meta"><bdi>{{ $row['line'] }}</bdi></span>
        </span>
    </span>
    @unless (\App\Services\Workspaces\ConsoleAccess::readOnly())
        <button type="button" @class(['v2-row-pin', 'is-on' => $row['pinned']]) x-on:click.stop="$wire.togglePin('{{ $row['id'] }}')"
            aria-pressed="{{ $row['pinned'] ? 'true' : 'false' }}" aria-label="{{ $row['pinned'] ? __('Unpin') : __('Pin to the top') }}"
            title="{{ $row['pinned'] ? __('Unpin') : __('Pin to the top') }}" data-pin="{{ $row['id'] }}">
            <x-el.v2.icon name="star" :filled="$row['pinned']" />
        </button>
    @endunless
    <span class="v2-row-value">
        <time datetime="{{ $row['iso'] }}" title="{{ $row['full_time'] }}">{{ $row['time'] }}</time>
        <span class="v2-row-length">{{ $row['length'] }}</span>
    </span>
</div>
