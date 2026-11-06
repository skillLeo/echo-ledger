@php
    $t = $report['totals'];
    $head = 'padding:.5rem .75rem;text-align:right';
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $num = $cell.'text-align:right;font-variant-numeric:tabular-nums;';
    $percent = fn (?float $p): string => $p === null ? '—' : number_format($p, 1).'%';
@endphp
<div style="display:flex;flex-direction:column;gap:1.5rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem">
        @foreach ([
            ['Minutes', number_format($t['minutes'], 1), $from.' to '.$to],
            ['Tokens', number_format($t['input_tokens'] + $t['output_tokens']), number_format($t['input_tokens']).' read · '.number_format($t['output_tokens']).' written'],
            ['Estimated cost', '$'.number_format($t['cost_usd'], 2), 'From the price table'],
        ] as [$label, $value, $hint])
            <x-filament::section compact>
                <div style="font-size:.8rem;opacity:.7">{{ $label }}</div>
                <div style="font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $value }}</div>
                <div style="font-size:.75rem;opacity:.6">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    @include('filament.pages.usage-trend', ['trend' => $trend])

    <x-filament::section heading="By provider" description="Anthropic is Show as, project AI and screen descriptions together, with each use below it.">
        @if ($report['providers'] === [])
            <p style="opacity:.6">No usage in this period.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <thead><tr style="text-align:left;opacity:.7"><th style="padding:.5rem .75rem">Provider</th><th style="{{ $head }}">Minutes</th><th style="{{ $head }}">Tokens read</th><th style="{{ $head }}">Tokens written</th><th style="{{ $head }}">Cost</th></tr></thead>
                    <tbody>
                        @foreach ($report['providers'] as $provider)
                            <tr style="font-weight:600">
                                <td style="{{ $cell }}">{{ $provider['label'] }}</td>
                                <td style="{{ $num }}">{{ number_format($provider['minutes'], 1) }}</td>
                                <td style="{{ $num }}">{{ number_format($provider['input_tokens']) }}</td>
                                <td style="{{ $num }}">{{ number_format($provider['output_tokens']) }}</td>
                                <td style="{{ $num }}">${{ number_format($provider['cost_usd'], 2) }}</td>
                            </tr>
                            @foreach ($provider['use_cases'] as $useCase)
                                <tr style="opacity:.75">
                                    <td style="{{ $cell }}padding-left:1.75rem">{{ $useCase['label'] }}</td>
                                    <td style="{{ $num }}">{{ number_format($useCase['minutes'], 1) }}</td>
                                    <td style="{{ $num }}">{{ number_format($useCase['input_tokens']) }}</td>
                                    <td style="{{ $num }}">{{ number_format($useCase['output_tokens']) }}</td>
                                    <td style="{{ $num }}">${{ number_format($useCase['cost_usd'], 2) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="By workspace" description="The period's numbers, and how much of its plan each workspace has used this month.">
        @if ($report['workspaces'] === [])
            <p style="opacity:.6">No usage in this period.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <thead><tr style="text-align:left;opacity:.7"><th style="padding:.5rem .75rem">Workspace</th><th style="padding:.5rem .75rem">Plan</th><th style="{{ $head }}">Minutes</th><th style="{{ $head }}">Tokens</th><th style="{{ $head }}">Cost</th><th style="{{ $head }}">Plan minutes</th><th style="{{ $head }}">Plan storage</th><th style="{{ $head }}">AI credits</th></tr></thead>
                    <tbody>
                        @foreach ($report['workspaces'] as $workspace)
                            <tr>
                                <td style="{{ $cell }}">{{ $workspace['name'] }}</td>
                                <td style="{{ $cell }}">{{ $workspace['plan'] }}</td>
                                <td style="{{ $num }}">{{ number_format($workspace['minutes'], 1) }}</td>
                                <td style="{{ $num }}">{{ number_format($workspace['input_tokens'] + $workspace['output_tokens']) }}</td>
                                <td style="{{ $num }}">${{ number_format($workspace['cost_usd'], 2) }}</td>
                                <td style="{{ $num }}">{{ $percent($workspace['minutes_percent']) }}</td>
                                <td style="{{ $num }}">{{ $percent($workspace['storage_percent']) }}</td>
                                <td style="{{ $num }}">{{ $percent($workspace['ai_percent']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @include('filament.pages.usage-people', ['people' => $report['people'], 'description' => 'Everyone on the server who used something in this period. Numbers only, never what they recorded.'])
</div>
