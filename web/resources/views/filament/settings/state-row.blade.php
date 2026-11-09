{{-- Under the profile card: at most one row, the most urgent thing (a request's stage, a plan ending, the free key). --}}
@php($state = \App\Filament\Support\UpgradeFlow::stateRow($context))
@if ($state !== null)
    <div class="el-set-box el-state" role="status" aria-live="polite" data-state-row>
        <div class="el-set-row has-icon" style="padding-block:4px">
            @svg($state['icon'], ['class' => 'el-set-icon '.match ($state['tone']) { 'warning' => 'is-warning', 'success' => 'is-success', default => '' }, 'aria-hidden' => 'true'])
            <a href="{{ $state['url'] }}" class="el-set-text" style="min-height:44px;justify-content:center">
                <span class="el-set-title">{{ $state['text'] }}</span>
            </a>
            @if ($state['action'])
                <a href="{{ $state['action']['url'] }}" class="el-btn el-btn-text">{{ $state['action']['label'] }}</a>
            @elseif ($state['dismiss'])
                <button type="button" class="el-icon-x" wire:click="dismissRequest" aria-label="Dismiss">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
            @else
                @svg('heroicon-o-chevron-right', ['class' => 'el-set-chevron', 'aria-hidden' => 'true'])
            @endif
        </div>
    </div>
@endif
