{{-- Failed uploads (since v1.6, P5.3): one slim line above the recordings while something failed; See why opens the view. --}}
<x-filament-widgets::widget>
    @unless ($hidden)
        <div class="el-waiting-chip el-failed-chip" data-failed-uploads>
            @svg('heroicon-o-exclamation-circle', 'el-waiting-chip-icon', ['aria-hidden' => 'true'])
            <span class="el-waiting-chip-label">{{ $label }}</span>
            <span class="el-waiting-chip-dot" aria-hidden="true">·</span>
            <a href="{{ $url }}" class="el-waiting-chip-action" wire:navigate>See why</a>
        </div>
    @endunless
</x-filament-widgets::widget>
