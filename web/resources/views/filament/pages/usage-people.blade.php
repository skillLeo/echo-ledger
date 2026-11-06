{{-- Minutes, tokens and cost per person (since v1.5): numbers only, never what they recorded. --}}
@php
    $head = 'padding:.5rem .75rem;text-align:right';
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $num = $cell.'text-align:right;font-variant-numeric:tabular-nums;';
@endphp
<x-filament::section heading="By person" :description="$description ?? 'Dollars from the price table. Never what anyone recorded.'">
    @if ($people === [])
        <p style="opacity:.6">Nobody has used anything in this period.</p>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th style="padding:.5rem .75rem">Person</th><th style="{{ $head }}">Minutes</th><th style="{{ $head }}">Tokens read</th><th style="{{ $head }}">Tokens written</th><th style="{{ $head }}">Cost</th><th style="{{ $head }}">% of plan minutes</th></tr></thead>
                <tbody>
                    @foreach ($people as $person)
                        <tr>
                            <td style="{{ $cell }}">{{ $person['name'] }}<div style="font-size:.75rem;opacity:.6">{{ $person['email'] }}</div></td>
                            <td style="{{ $num }}">{{ number_format($person['minutes'], 1) }}</td>
                            <td style="{{ $num }}">{{ number_format($person['input_tokens']) }}</td>
                            <td style="{{ $num }}">{{ number_format($person['output_tokens']) }}</td>
                            <td style="{{ $num }}">${{ number_format($person['cost_usd'], 2) }}</td>
                            <td style="{{ $num }}">{{ $person['plan_percent'] === null ? '—' : number_format($person['plan_percent'], 1).'%' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
