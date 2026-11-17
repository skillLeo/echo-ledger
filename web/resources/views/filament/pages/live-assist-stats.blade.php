{{-- Live Assist's numbers for the super admin (App\Filament\Admin\Pages\LiveAssistStats). Numbers only. --}}
@php
    $t = $stats['totals'];
    $head = 'padding:.5rem .75rem;text-align:right';
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $num = $cell.'text-align:right;font-variant-numeric:tabular-nums;';
    $usd = fn (?float $v): string => $v === null ? '—' : '$'.number_format($v, $v < 1 ? 4 : 2);
    $pct = fn (?float $p): string => $p === null ? '—' : number_format($p, 1).'%';
    $ms = fn (?float $v): string => $v === null ? '—' : number_format($v, $v < 100 ? 1 : 0).' ms';
    $tokens = fn (int $n): string => number_format($n);
    $modeNames = ['fast' => 'Fast', 'balanced' => 'Balanced', 'deep' => 'Deep', 'max' => 'Max', 'practice' => 'Practice'];
@endphp
<div style="display:flex;flex-direction:column;gap:1.5rem" data-live-assist-stats>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1rem">
        @foreach ([
            ['Cost', $usd($t['cost_usd']), number_format($t['calls']).' model calls'],
            ['Assist time', number_format($t['hours'], 1).' h', number_format($t['sessions']).' sessions'],
            ['Cost per hour', $usd($t['hours'] > 0 ? $t['cost_usd'] / $t['hours'] : null), 'all modes together'],
            ['Cache hits', $pct($t['cache_hit_percent']), 'of the input read from the prompt cache'],
            ['Errors', $pct($stats['error_share']), 'of the suggestions'],
        ] as [$label, $value, $hint])
            <x-filament::section compact>
                <div style="font-size:.8rem;opacity:.7">{{ $label }}</div>
                <div style="font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $value }}</div>
                <div style="font-size:.75rem;opacity:.7">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="By plan" description="The sold plan the session started on, with the Live Assist tier it gives." data-assist-plans>
        @if ($stats['plans'] === [])
            <p style="margin:0;opacity:.7">No Live Assist in this period.</p>
        @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th scope="col" style="padding:.5rem .75rem">Plan</th><th scope="col" style="padding:.5rem .75rem">Tier</th>
                    <th scope="col" style="{{ $head }}">Sessions</th><th scope="col" style="{{ $head }}">Hours</th><th scope="col" style="{{ $head }}">Calls</th>
                    <th scope="col" style="{{ $head }}">Cost</th><th scope="col" style="{{ $head }}">Per hour</th><th scope="col" style="{{ $head }}">Cache hits</th></tr></thead>
                <tbody>
                @foreach ($stats['plans'] as $row)
                    <tr><td style="{{ $cell }}">{{ $row['name'] }}</td><td style="{{ $cell }}">{{ $row['tier'] !== null ? ucfirst($row['tier']) : '—' }}</td>
                        <td style="{{ $num }}">{{ number_format($row['sessions']) }}</td><td style="{{ $num }}">{{ number_format($row['hours'], 1) }}</td>
                        <td style="{{ $num }}">{{ number_format($row['calls']) }}</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td>
                        <td style="{{ $num }}">{{ $usd($row['cost_per_hour_usd']) }}</td><td style="{{ $num }}">{{ $pct($row['cache_hit_percent']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="By model" data-assist-models>
        @if ($stats['models'] === [])
            <p style="margin:0;opacity:.7">No model calls in this period.</p>
        @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th scope="col" style="padding:.5rem .75rem">Model</th>
                    <th scope="col" style="{{ $head }}">Calls</th><th scope="col" style="{{ $head }}">Input</th><th scope="col" style="{{ $head }}">Cached</th>
                    <th scope="col" style="{{ $head }}">Written</th><th scope="col" style="{{ $head }}">Output</th><th scope="col" style="{{ $head }}">Cache hits</th><th scope="col" style="{{ $head }}">Cost</th></tr></thead>
                <tbody>
                @foreach ($stats['models'] as $row)
                    <tr><td style="{{ $cell }}">{{ $row['model'] }} <span style="opacity:.6">{{ $row['provider'] }}</span></td>
                        <td style="{{ $num }}">{{ number_format($row['calls']) }}</td><td style="{{ $num }}">{{ $tokens($row['input_tokens']) }}</td>
                        <td style="{{ $num }}">{{ $tokens($row['cached_tokens']) }}</td><td style="{{ $num }}">{{ $tokens($row['written_tokens']) }}</td>
                        <td style="{{ $num }}">{{ $tokens($row['output_tokens']) }}</td><td style="{{ $num }}">{{ $pct($row['cache_hit_percent']) }}</td>
                        <td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Cost per hour by mode" description="A session's model calls count with its mode; Practice apart." data-assist-modes>
        @if ($stats['modes'] === [])
            <p style="margin:0;opacity:.7">No sessions in this period.</p>
        @else
            <div style="overflow-x:auto"><table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th scope="col" style="padding:.5rem .75rem">Mode</th><th scope="col" style="{{ $head }}">Sessions</th>
                    <th scope="col" style="{{ $head }}">Hours</th><th scope="col" style="{{ $head }}">Cost</th><th scope="col" style="{{ $head }}">Per hour</th></tr></thead>
                <tbody>
                @foreach ($stats['modes'] as $row)
                    <tr><td style="{{ $cell }}">{{ $modeNames[$row['mode']] ?? $row['mode'] }}</td><td style="{{ $num }}">{{ number_format($row['sessions']) }}</td>
                        <td style="{{ $num }}">{{ number_format($row['hours'], 1) }}</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td>
                        <td style="{{ $num }}">{{ $usd($row['cost_per_hour_usd']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Latency" :description="'p50 and p95 of '.number_format($stats['latency']['rows']).' suggestions (the newest '.number_format(\App\Services\Assist\AssistStats::MAX_TIMED_ROWS).' at most). Targets: first word p50 ≤ 1.5 s Fast, relay overhead p95 ≤ 30 ms.'" data-assist-latency>
        <div style="overflow-x:auto"><table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead><tr style="text-align:left;opacity:.7"><th scope="col" style="padding:.5rem .75rem">Stage</th><th scope="col" style="{{ $head }}">p50</th>
                <th scope="col" style="{{ $head }}">p95</th><th scope="col" style="{{ $head }}">Measured</th></tr></thead>
            <tbody>
            @foreach ($stages as $key => $label)
                @php($p = $stats['latency']['all'][$key])
                <tr><td style="{{ $cell }}">{{ $label }}</td><td style="{{ $num }}">{{ $ms($p['p50']) }}</td><td style="{{ $num }}">{{ $ms($p['p95']) }}</td><td style="{{ $num }}">{{ number_format($p['n']) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @if ($stats['latency']['models'] !== [])
            <h3 style="font-weight:600;margin:1.25rem 0 .5rem">Per model: end of turn → first token</h3>
            <div style="overflow-x:auto"><table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th scope="col" style="padding:.5rem .75rem">Model</th><th scope="col" style="{{ $head }}">p50</th>
                    <th scope="col" style="{{ $head }}">p95</th><th scope="col" style="{{ $head }}">Relay overhead p95</th><th scope="col" style="{{ $head }}">Measured</th></tr></thead>
                <tbody>
                @foreach ($stats['latency']['models'] as $model => $byStage)
                    @php($p = $byStage['end_of_turn_to_first_token_ms'])
                    <tr><td style="{{ $cell }}">{{ $model }}</td><td style="{{ $num }}">{{ $ms($p['p50']) }}</td><td style="{{ $num }}">{{ $ms($p['p95']) }}</td>
                        <td style="{{ $num }}">{{ $ms($byStage['relay_overhead_ms']['p95']) }}</td><td style="{{ $num }}">{{ number_format($p['n']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-filament::section>

    @if ($stats['outcomes'] !== [])
        <x-filament::section heading="How suggestions ended" data-assist-outcomes>
            <ul style="margin:0;padding-left:1.25rem;list-style:disc">
                @foreach ($stats['outcomes'] as $outcome => $count)
                    <li>{{ ucfirst(str_replace('_', ' ', $outcome)) }}: {{ number_format($count) }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</div>
