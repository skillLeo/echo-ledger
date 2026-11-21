{{--
    Home's "Needs action" in the new design (WEB-HOM-005): one quiet line, each thing a link to its Library view
    (App\Filament\Widgets\V2NeedsActionWidget), and × to close it until something rises. Nothing is drawn when nothing
    needs the person or they closed it.
--}}
<x-filament-widgets::widget>
    @if ($items !== [])
        <p class="v2-help" data-needs-action>
            <span>{{ __('Needs action') }}:</span>
            @foreach ($items as $i => $item)
                @if ($i > 0)<span aria-hidden="true"> · </span>@endif
                <a href="{{ $item['url'] }}" class="v2-link" data-needs="{{ $item['key'] }}">{{ $item['words'] }}</a>
            @endforeach
            <button type="button" class="v2-icon-btn" wire:click="dismiss({{ json_encode(array_column($items, 'count', 'key')) }})" aria-label="{{ __('Dismiss') }}" title="{{ __('Dismiss') }}" data-dismiss="home"><x-el.v2.icon name="close" /></button>
        </p>
    @endif
</x-filament-widgets::widget>
