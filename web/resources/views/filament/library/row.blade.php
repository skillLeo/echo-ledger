{{--
    One Library row, as the phone draws it. From 1024 px a plain click opens the recording in the drawer on the right
    instead of its page (Ctrl, Cmd, Shift and middle clicks still open it in the usual way). Since v1.6 (P4.2) a row
    can be dragged onto the Inbox, Pinned or a project.
--}}
@php($row = \App\Filament\Support\RecordingRow::for($getRecord()))
{{-- Since v1.6 (P5.3): in Failed uploads each row says what went wrong and what happens next. --}}
@php($problem = property_exists($this, 'activeTab') && (string) $this->activeTab === 'failed' ? \App\Filament\Support\FailedUpload::for($getRecord()) : null)
<div class="el-row" data-recording="{{ $row['id'] }}" @if (! \App\Services\Workspaces\ConsoleAccess::readOnly()) draggable="true" @endif
    x-on:click="if (window.matchMedia('(min-width: 1024px)').matches && ! ($event.ctrlKey || $event.metaKey || $event.shiftKey || $event.button !== 0) && typeof $wire.selectRecording === 'function') { $event.preventDefault(); $wire.selectRecording('{{ $row['id'] }}') }">
    @include('filament.library.tile', ['row' => $row])
    <span class="el-row-main">
        <span class="el-row-top">
            <span class="el-row-title">{{ $row['title'] }}</span>
            <time class="el-row-time" datetime="{{ $row['iso'] }}" title="{{ $row['full_time'] }}">{{ $row['time'] }}</time>
        </span>
        <span class="el-row-sub">
            <span class="el-sr">{{ $row['app'] }} · {{ $row['device'] }} · </span>
            <span class="el-row-meta">{{ $row['meta'] }}</span>
            @if ($row['progress'] !== null && ! in_array($row['status']['label'] ?? null, ['Waiting', 'Failed', 'No audio'], true))
                @include('filament.library.progress', ['progress' => $row['progress'], 'compact' => true])
            @elseif ($row['status'] !== null)
                @include('filament.library.status', ['status' => $row['status']])
            @endif
        </span>
        @if ($problem !== null)
            <span class="el-row-problem" data-problem>
                <strong>{{ $problem['title'] }}.</strong>
                <span>{{ $problem['detail'] }}</span>
            </span>
        @endif
    </span>
</div>
