{{-- W-209: a name or value inside a translated sentence is isolated in right to left, so the sentence keeps the page's direction (D4 §4). --}}
@php
    $iso = fn (mixed $value): string => \App\Filament\Support\ConsoleV2::rtl() ? "\u{2068}".$value."\u{2069}" : (string) $value;
@endphp
{{-- The last six months, oldest first (since v1.5). --}}
@php
    $most = max(1, ...array_map(fn (array $m): float => $m['minutes'], $trend));
    $cell = 'padding:.4rem .75rem;border-top:1px solid var(--gray-200);';
@endphp
<x-filament::section :heading="__('Last six months')">
    <div style="overflow-x:auto">
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <tbody>
                @foreach ($trend as $month)
                    <tr>
                        <td style="{{ $cell }}white-space:nowrap">{{ $month['label'] }}</td>
                        <td style="{{ $cell }}width:60%">
                            <div role="img" aria-label="{{ __(':month: :n minutes', ['month' => $iso($month['label']), 'n' => number_format($month['minutes'], 1)]) }}" style="height:.5rem;border-radius:999px;background:var(--gray-200);overflow:hidden">
                                <div style="height:100%;width:{{ round($month['minutes'] / $most * 100, 1) }}%;background:var(--primary-500);border-radius:999px"></div>
                            </div>
                        </td>
                        <td style="{{ $cell }}text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap">{{ __(':n min', ['n' => number_format($month['minutes'], 1)]) }}</td>
                        <td style="{{ $cell }}text-align:right;font-variant-numeric:tabular-nums">${{ number_format($month['cost_usd'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament::section>
