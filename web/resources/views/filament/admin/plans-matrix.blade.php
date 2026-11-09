{{-- Admin panel → Plans → Features and plans (since v1.6, P2.3): a box per paid feature and plan; the free ones are in every plan. --}}
@php
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $head = 'padding:.5rem .75rem;text-align:center;font-weight:600';
@endphp
<x-filament::section heading="Features and plans" description="Tick a box to put a feature in a plan; it applies at once to every workspace on the plan." data-plans-matrix>
    <div style="overflow-x:auto">
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="padding:.5rem .75rem;text-align:left;opacity:.7;font-weight:500">Feature</th>
                    @foreach ($plans as $plan)
                        <th style="{{ $head }}">{!! \App\Filament\Support\PlanLock::badgeFor($plan, locked: false)->toHtml() !!}<div style="font-weight:500;opacity:.8;margin-top:.25rem">{{ $plan->name }}</div></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($features as $feature => $label)
                    <tr>
                        <td style="{{ $cell }}">{{ $label }}</td>
                        @foreach ($plans as $plan)
                            @php $on = $plan->allows($feature); @endphp
                            <td style="{{ $cell }}text-align:center">
                                <input type="checkbox" @checked($on) @disabled(! $canManage)
                                       aria-label="{{ $label }} in {{ $plan->name }}"
                                       data-feature="{{ $feature }}" data-plan="{{ $plan->key }}"
                                       wire:click="toggleFeature({{ $plan->id }}, '{{ $feature }}')" wire:loading.attr="disabled"
                                       style="width:1.1rem;height:1.1rem;accent-color:var(--primary-600)">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                @foreach ($free as $label)
                    <tr style="opacity:.7">
                        <td style="{{ $cell }}">{{ $label }} <span style="font-size:.75rem">(free, always in)</span></td>
                        @foreach ($plans as $plan)
                            <td style="{{ $cell }}text-align:center"><input type="checkbox" checked disabled aria-label="{{ $label }} in {{ $plan->name }}" style="width:1.1rem;height:1.1rem"></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament::section>
