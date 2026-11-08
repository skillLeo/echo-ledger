{{--
    A quiet poll while call notes are written or Show as views made; it goes away with the work. Since v1.5 (Phase 1C)
    the transcript is readable while its view is made, with a slim "Improving Roman Urdu… 60 %" bar; the bar keeps its
    place (and says the view is in) once it is done, so nothing below it moves.
--}}
<div wire:poll.{{ $interval }}="refreshWork" @if (empty($bar)) hidden @endif>
    @if (! empty($bar))
        <div class="el-improving" role="status" data-done="{{ $bar['percent'] >= 100 ? 'true' : 'false' }}">
            <span class="el-improving-text">{{ $bar['label'] }}</span>
            <span class="el-improving-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $bar['percent'] }}" aria-label="{{ $bar['label'] }}">
                <span class="el-improving-fill" style="width: {{ $bar['percent'] }}%"></span>
            </span>
        </div>
    @endif
</div>
