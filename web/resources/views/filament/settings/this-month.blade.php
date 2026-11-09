{{-- The Overview's "This month": minutes, storage and devices, then Plan & usage. --}}
@php($meters = array_values(array_filter(\App\Filament\Support\UpgradeFlow::meters($context), fn (array $m): bool => in_array($m['id'], ['plan.usage.minutes', 'plan.usage.storage', 'plan.usage.devices'], true))))
<x-el.section title="This month" class="el-set-only-wide">
    @foreach ($meters as $meter)
        @include('filament.settings.meter', ['meter' => $meter, 'withId' => false])
    @endforeach
    <x-el.row title="Plan & usage" :href="\App\Filament\Auth\EditProfile::settingsUrl('plan')" :value="$context->plan()->name" chevron />
</x-el.section>
