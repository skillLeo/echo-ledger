{{--
    A quiet poll while call notes are written or Show as views made; it goes away with the work. Since v1.5 (Phase 1C)
    the transcript is readable while its view is made, with a slim bar; the bar keeps its place (and says the view is in)
    once it is done, so nothing below it moves. Since U19: a small spinner and "Converting to Roman Urdu… 60%" from the
    moment the transcript is there, and "Couldn't convert to Roman Urdu · Try again" when it could not be made.
--}}
<div wire:poll.{{ $interval }}="refreshWork" @if (empty($bar)) hidden @endif>
    @if (! empty($bar))
        @php($failed = (bool) ($bar['failed'] ?? false))
        <div class="el-improving" role="status" data-done="{{ $bar['percent'] >= 100 ? 'true' : 'false' }}" @if ($failed) data-failed @endif>
            @if ($failed)
                @svg('heroicon-m-exclamation-circle', ['class' => 'el-improving-icon', 'aria-hidden' => 'true'])
                <span class="el-improving-text">{{ $bar['label'] }}</span>
                @unless (\App\Services\Workspaces\ConsoleAccess::readOnly())
                    <span aria-hidden="true">·</span>
                    <button type="button" class="el-improving-retry" wire:click="retryViews" wire:loading.attr="disabled" data-retry-views>{{ __('Try again') }}</button>
                @endunless
            @else
                @if ($bar['percent'] < 100)
                    <span class="el-spinner" aria-hidden="true" data-converting></span>
                @endif
                <span class="el-improving-text">{{ $bar['label'] }}</span>
                <span class="el-improving-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $bar['percent'] }}" aria-label="{{ $bar['label'] }}">
                    <span class="el-improving-fill" style="width: {{ $bar['percent'] }}%"></span>
                </span>
            @endif
        </div>
    @endif
</div>
