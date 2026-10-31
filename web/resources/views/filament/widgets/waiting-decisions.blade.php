<x-filament-widgets::widget>
    @if ($line !== null)
        @include('filament.widgets.waiting-card', ['line' => $line, 'reviewUrl' => $reviewUrl])
    @endif
</x-filament-widgets::widget>
