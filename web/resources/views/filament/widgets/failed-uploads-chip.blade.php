{{--
    Failed uploads (since v1.6, P5.3): one slim line above the recordings while something failed; a click anywhere on it
    opens the view (since v1.6.1, a notice: ×, swipe, closed until another one fails or the next visit).
--}}
<x-filament-widgets::widget>
    @unless ($hidden)
        <x-notice id="failed-uploads" :state="$state" :href="$url" class="el-waiting-chip el-failed-chip" data-failed-uploads>
            @svg('heroicon-o-exclamation-circle', 'el-waiting-chip-icon', ['aria-hidden' => 'true'])
            <span class="el-waiting-chip-label">{{ $label }}</span>
            <span class="el-waiting-chip-dot" aria-hidden="true">·</span>
            <a href="{{ $url }}" class="el-waiting-chip-action el-stretch" wire:navigate>See why</a>
        </x-notice>
    @endunless
</x-filament-widgets::widget>
