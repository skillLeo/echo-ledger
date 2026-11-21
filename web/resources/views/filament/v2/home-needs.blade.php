{{--
    Home's "Needs action" in the new design (WEB-HOM-005): one quiet line, each thing a link to its Library view
    (App\Filament\Widgets\V2NeedsActionWidget). Nothing is drawn when nothing needs the person.
--}}
<x-filament-widgets::widget>
    <p class="v2-help" data-needs-action>
        <span>{{ __('Needs action') }}:</span>
        @foreach ($items as $i => $item)
            @if ($i > 0)<span aria-hidden="true"> · </span>@endif
            <a href="{{ $item['url'] }}" class="v2-link" data-needs="{{ $item['key'] }}">{{ $item['words'] }}</a>
        @endforeach
    </p>
</x-filament-widgets::widget>
