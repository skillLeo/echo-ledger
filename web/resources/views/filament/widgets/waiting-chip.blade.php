{{-- Calls waiting for Keep or Discard (since v1.5): one slim line above the recordings, only while something waits. --}}
<x-filament-widgets::widget>
    <div class="el-waiting-chip" data-waiting-decisions @if ($detail !== null) title="{{ $detail }}. The phone or computer that recorded them decides Keep or Discard." @endif>
        @svg('heroicon-o-clock', 'el-waiting-chip-icon', ['aria-hidden' => 'true'])
        <span class="el-waiting-chip-label">{{ $label }}</span>
        <span class="el-waiting-chip-dot" aria-hidden="true">·</span>
        <a href="{{ $reviewUrl }}" class="el-waiting-chip-action">Review</a>
    </div>
</x-filament-widgets::widget>
