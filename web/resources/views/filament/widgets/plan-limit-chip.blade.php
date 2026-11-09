{{-- The plan's minutes or storage used up (since v1.6.1), or Free without a Groq key (since v1.7): a notice, ×, swipe, no timer. --}}
<x-filament-widgets::widget>
    @if ($limit !== null)
        <x-notice id="plan-limit" :state="$state" :href="$url" class="el-waiting-chip el-failed-chip" data-plan-limit="{{ $limit }}">
            @svg($limit === 'key' ? 'heroicon-o-key' : 'heroicon-o-chart-bar', 'el-waiting-chip-icon', ['aria-hidden' => 'true'])
            <span class="el-waiting-chip-label">{{ $label }}</span>
            <span class="el-waiting-chip-dot" aria-hidden="true">·</span>
            <a href="{{ $url }}" class="el-waiting-chip-action el-stretch">{{ $action }}</a>
        </x-notice>
    @endif
</x-filament-widgets::widget>
