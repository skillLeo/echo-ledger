{{--
    One v2.0 Library row: the source's glyph on a neutral circle, the title, then one status (only what needs you) and
    the second line; at the end the time above the length. From 1024 px a plain click opens the recording beside the
    list, as in the classic Library (Ctrl, Cmd, Shift and middle clicks open its page).
--}}
@php($row = \App\Filament\Support\V2RecordingRow::for($getRecord()))
<div class="v2-lib-row" data-recording="{{ $row['id'] }}"
    x-on:click="if (window.matchMedia('(min-width: 1024px)').matches && ! ($event.ctrlKey || $event.metaKey || $event.shiftKey || $event.button !== 0) && typeof $wire.selectRecording === 'function') { $event.preventDefault(); $wire.selectRecording('{{ $row['id'] }}') }">
    <span class="v2-row-tile" title="{{ $row['source'] }}"><x-el.v2.icon :name="$row['icon']" /></span>
    <span class="v2-row-main">
        <span class="v2-row-title"><bdi>{{ $row['title'] }}</bdi></span>
        <span class="v2-lib-line">
            @if ($row['status'] !== null)
                <span @class(['v2-status', 'is-'.$row['status']['tone']])>
                    <x-el.v2.icon :name="$row['status']['icon']" />{{ $row['status']['label'] }}
                </span>
            @endif
            <span class="v2-row-meta"><bdi>{{ $row['line'] }}</bdi></span>
        </span>
    </span>
    <span class="v2-row-value">
        <time datetime="{{ $row['iso'] }}" title="{{ $row['full_time'] }}">{{ $row['time'] }}</time>
        <span class="v2-row-length">{{ $row['length'] }}</span>
    </span>
</div>
