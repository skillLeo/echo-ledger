@php
    $t = $report['totals'];
    $head = 'padding:.5rem .75rem;text-align:right';
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $num = $cell.'text-align:right;font-variant-numeric:tabular-nums;';
    $percent = fn (?float $p): string => $p === null ? '—' : number_format($p, 1).'%';
@endphp
<div style="display:flex;flex-direction:column;gap:1.5rem">
    @php
        $change = function (float $now, ?float $before): ?string {
            if ($before === null) {
                return null;
            }
            if ($before <= 0.0) {
                return $now > 0.0 ? 'new in this period' : 'same as the period before';
            }
            $delta = ($now - $before) / $before * 100;

            return ($delta >= 0 ? '+' : '−').number_format(abs($delta), 1).'% on the period before';
        };
        $p = $previous;
    @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem" data-usage-kpis>
        @foreach ([
            ['Minutes', number_format($t['minutes'], 1), $change((float) $t['minutes'], $p === null ? null : (float) $p['minutes']) ?? $from.' to '.$to],
            ['Tokens', number_format($t['input_tokens'] + $t['output_tokens']), $change((float) ($t['input_tokens'] + $t['output_tokens']), $p === null ? null : (float) ($p['input_tokens'] + $p['output_tokens'])) ?? number_format($t['input_tokens']).' read · '.number_format($t['output_tokens']).' written'],
            ['Estimated cost', '$'.number_format($t['cost_usd'], 2), $change((float) $t['cost_usd'], $p === null ? null : (float) $p['cost_usd']) ?? 'From the price table'],
            ['Budget this month', $budget['budget'] === null ? 'No alert' : '$'.number_format($budget['spent'], 2).' of $'.number_format($budget['budget'], 2), $budget['budget'] === null ? 'Set one with Budget alert' : number_format((float) $budget['percent'], 1).'% used; alerts at 80% and 100%'],
        ] as [$label, $value, $hint])
            <x-filament::section compact>
                <div style="font-size:.8rem;opacity:.7">{{ $label }}</div>
                <div style="font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $value }}</div>
                <div style="font-size:.75rem;opacity:.6">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    @php $most = max(0.1, ...array_map(fn (array $d): float => $d['minutes'], $daily ?: [['minutes' => 0.0]])); @endphp
    <x-filament::section heading="Minutes per day" description="{{ $from }} to {{ $to }}" data-usage-daily>
        @if ($daily === [])
            <p style="opacity:.6">No days in this period.</p>
        @else
            <div style="display:flex;align-items:flex-end;gap:2px;height:9rem;overflow-x:auto" role="list">
                @foreach ($daily as $day)
                    <div role="listitem" style="flex:1 0 .5rem;display:flex;flex-direction:column;justify-content:flex-end;height:100%">
                        <div role="img" aria-label="{{ $day['label'] }}: {{ number_format($day['minutes'], 1) }} minutes, ${{ number_format($day['cost_usd'], 2) }}"
                             title="{{ $day['label'] }}: {{ number_format($day['minutes'], 1) }} min · ${{ number_format($day['cost_usd'], 2) }}"
                             style="height:{{ max($day['minutes'] > 0 ? 2 : 0, round($day['minutes'] / $most * 100, 1)) }}%;background:var(--primary-500);border-radius:3px 3px 0 0"></div>
                    </div>
                @endforeach
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.75rem;opacity:.6;margin-top:.25rem"><span>{{ $daily[0]['label'] }}</span><span>{{ $daily[count($daily) - 1]['label'] }}</span></div>
        @endif
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem">
        <x-filament::section heading="By plan" data-usage-plans>
            @if ($plans === [])
                <p style="opacity:.6">No usage in this period.</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <thead><tr style="text-align:left;opacity:.7"><th style="padding:.5rem .75rem">Plan</th><th style="{{ $head }}">Workspaces</th><th style="{{ $head }}">Minutes</th><th style="{{ $head }}">Cost</th></tr></thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            <tr><td style="{{ $cell }}">{{ $plan['plan'] }}</td><td style="{{ $num }}">{{ $plan['workspaces'] }}</td><td style="{{ $num }}">{{ number_format($plan['minutes'], 1) }}</td><td style="{{ $num }}">${{ number_format($plan['cost_usd'], 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
        <x-filament::section heading="Top customers" description="By cost in this period." data-usage-top>
            @if ($top === [])
                <p style="opacity:.6">No usage in this period.</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($top as $person)
                            @php $link = $customerUrl($person['id']); @endphp
                            <tr>
                                <td style="{{ $cell }}">@if ($link)<a href="{{ $link }}" style="text-decoration:underline">{{ $person['name'] }}</a>@else{{ $person['name'] }}@endif<div style="font-size:.75rem;opacity:.6">{{ $person['email'] }}</div></td>
                                <td style="{{ $num }}">{{ number_format($person['minutes'], 1) }} min</td>
                                <td style="{{ $num }}">${{ number_format($person['cost_usd'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
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
