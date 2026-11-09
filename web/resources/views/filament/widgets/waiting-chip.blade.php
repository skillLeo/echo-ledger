{{--
    Calls waiting for Keep or Discard (since v1.5): one slim line above the recordings, only while something waits; a
    click anywhere on it opens the Waiting view (since v1.6.1, a notice: ×, swipe, closed until another call waits or
    the next visit).
--}}
<x-filament-widgets::widget>
    <x-notice id="waiting-calls" :state="$state" :href="$reviewUrl" class="el-waiting-chip" data-waiting-decisions :title="$detail !== null ? $detail.'. The phone or computer that recorded them decides Keep or Discard.' : null">
        @svg('heroicon-o-clock', 'el-waiting-chip-icon', ['aria-hidden' => 'true'])
        <span class="el-waiting-chip-label">{{ $label }}</span>
        <span class="el-waiting-chip-dot" aria-hidden="true">·</span>
        <a href="{{ $reviewUrl }}" class="el-waiting-chip-action el-stretch">Review</a>
    </x-notice>
</x-filament-widgets::widget>
