{{--
    One Library row, as the phone draws it. On wide screens a plain click shows the recording beside the list instead
    of opening it (Ctrl, Cmd, Shift and middle clicks still open it in the usual way).
--}}
@php($row = \App\Filament\Support\RecordingRow::for($getRecord()))
<div class="el-row" data-recording="{{ $row['id'] }}"
    x-on:click="if (window.matchMedia('(min-width: 1280px)').matches && ! ($event.ctrlKey || $event.metaKey || $event.shiftKey || $event.button !== 0) && typeof $wire.selectRecording === 'function') { $event.preventDefault(); $wire.selectRecording('{{ $row['id'] }}') }">
    @include('filament.library.tile', ['row' => $row])
    <span class="el-row-main">
        <span class="el-row-top">
            <span class="el-row-title">{{ $row['title'] }}</span>
            <time class="el-row-time" datetime="{{ $row['iso'] }}" title="{{ $row['full_time'] }}">{{ $row['time'] }}</time>
        </span>
        <span class="el-row-sub">
            <span class="el-sr">{{ $row['app'] }} · {{ $row['device'] }} · </span>
            <span class="el-row-meta">{{ $row['meta'] }}</span>
            @if ($row['status'] !== null)
                @include('filament.library.status', ['status' => $row['status']])
            @endif
        </span>
    </span>
</div>
