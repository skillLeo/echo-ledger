<x-filament-panels::page>
    <?php if (\App\Filament\Support\ConsoleV2::any()): ?>@include('filament.v2.object-menus', ['menus' => $this->objectMenus()])<?php endif; ?>
    <div class="el-activity" style="max-width: var(--content-max)">
        @if ($waiting !== null || $groups['needs'] !== [])
            <h2 class="el-section-header">{{ __('Needs action') }}</h2>
            @if ($waiting !== null)
                @include('filament.widgets.waiting-card', ['line' => $waiting, 'reviewUrl' => $reviewUrl])
            @endif
            @if ($groups['needs'] !== [])
                @include('filament.pages.activity-rows', ['items' => $groups['needs']])
            @endif
        @endif

        @foreach (['today' => __('Today'), 'earlier' => __('Earlier')] as $key => $heading)
            @if ($groups[$key] !== [])
                <h2 class="el-section-header" style="margin-top: var(--space-5)">{{ $heading }}</h2>
                @include('filament.pages.activity-rows', ['items' => $groups[$key]])
            @endif
        @endforeach

        @if ($waiting === null && array_merge(...array_values($groups)) === [])
            <div class="el-empty">
                <span class="el-empty-icon">@svg('heroicon-o-bell', ['aria-hidden' => 'true'])</span>
                <p class="el-empty-title">{{ __('Nothing new') }}</p>
                <p class="el-empty-text">{{ __('Finished transcripts, failed uploads and new devices show up here.') }}</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
