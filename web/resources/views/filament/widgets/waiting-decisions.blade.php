<x-filament-widgets::widget>
    @if ($line !== null)
        <x-filament::section compact icon="heroicon-o-clock" icon-color="warning" heading="Keep or discard?" data-waiting-decisions>
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$reviewUrl" size="sm">Review</x-filament::button>
            </x-slot>

            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $line }}</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The phone or computer that recorded them decides Keep or Discard.</p>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
