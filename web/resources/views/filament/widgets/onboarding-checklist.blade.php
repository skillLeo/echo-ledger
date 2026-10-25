@php
    $steps = $this->steps();
    $done = count(array_filter($steps, fn (array $step): bool => $step['done']));
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="'Finish setting up'" :description="$done.' of '.count($steps).' done'">
        <x-slot name="afterHeader">
            <x-filament::button color="gray" size="sm" wire:click="dismiss">Dismiss</x-filament::button>
        </x-slot>

        <ul class="grid gap-3" aria-label="Setup checklist">
            @foreach ($steps as $step)
                <li class="flex items-start gap-3" data-step="{{ $step['id'] }}" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                    @if ($step['done'])
                        <x-filament::icon icon="heroicon-m-check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-success-600 dark:text-success-400" />
                        <span class="sr-only">Done:</span>
                    @else
                        <span class="mt-1 h-4 w-4 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600" aria-hidden="true"></span>
                        <span class="sr-only">To do:</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium {{ $step['done'] ? 'text-gray-500 line-through dark:text-gray-400' : 'text-gray-950 dark:text-white' }}">{{ $step['title'] }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $step['hint'] }}</div>
                    </div>
                    @unless ($step['done'])
                        <a href="{{ $step['url'] }}" class="shrink-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $step['link'] }}</a>
                    @endunless
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
