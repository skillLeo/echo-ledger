{{-- The plan's minutes or storage used up (since v1.6.1): a notice that opens Usage; ×, swipe, no timer. --}}
<x-filament-widgets::widget>
    @if ($limit !== null)
        <x-notice id="plan-limit" :state="$state" :href="$url" class="el-waiting-chip el-failed-chip" data-plan-limit="{{ $limit }}">
            @svg('heroicon-o-chart-bar', 'el-waiting-chip-icon', ['aria-hidden' => 'true'])
            <span class="el-waiting-chip-label">{{ $label }}</span>
            <span class="el-waiting-chip-dot" aria-hidden="true">·</span>
            <a href="{{ $url }}" class="el-waiting-chip-action el-stretch">See usage</a>
        </x-notice>
    @endif
</x-filament-widgets::widget>
