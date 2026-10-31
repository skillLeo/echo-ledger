<x-filament-panels::page>
    <div class="el-activity" style="max-width: var(--content-max)">
        @if ($waiting !== null || $groups['needs'] !== [])
            <h2 class="el-section-header">Needs action</h2>
            @if ($waiting !== null)
                @include('filament.widgets.waiting-card', ['line' => $waiting, 'reviewUrl' => $reviewUrl])
            @endif
            @if ($groups['needs'] !== [])
                @include('filament.pages.activity-rows', ['items' => $groups['needs']])
            @endif
        @endif

        @foreach (['today' => 'Today', 'earlier' => 'Earlier'] as $key => $heading)
            @if ($groups[$key] !== [])
                <h2 class="el-section-header" style="margin-top: var(--space-5)">{{ $heading }}</h2>
                @include('filament.pages.activity-rows', ['items' => $groups[$key]])
            @endif
        @endforeach

        @if ($waiting === null && array_merge(...array_values($groups)) === [])
            <div class="el-empty">
                <span class="el-empty-icon">@svg('heroicon-o-bell', ['aria-hidden' => 'true'])</span>
                <p class="el-empty-title">Nothing new</p>
                <p class="el-empty-text">Finished transcripts, failed uploads and new devices show up here.</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
