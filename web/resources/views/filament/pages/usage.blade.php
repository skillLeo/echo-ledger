@php
    $t = $report['totals'];
    $min = fn (float $m): string => number_format($m, $m < 10 ? 1 : 0);
    $usd = fn (float $c): string => '$'.number_format($c, 2);
    $bytes = fn (?int $b): string => $b === null ? 'no limit' : \Illuminate\Support\Number::fileSize($b, precision: 1);
    $meter = function (float $used, ?float $limit): array {
        $pct = $limit !== null && $limit > 0 ? min(100, $used / $limit * 100) : null;
        $color = $pct === null ? 'var(--gray-400)' : ($pct >= 100 ? 'var(--danger-500)' : ($pct >= 80 ? 'var(--warning-500)' : 'var(--primary-500)'));

        return [$pct, $color];
    };
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $num = $cell.'text-align:right;font-variant-numeric:tabular-nums;';
@endphp

<div style="display:flex;flex-direction:column;gap:1.5rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem">
        @foreach ([
            ['Billable minutes', $min($t['minutes']), 'Streaming '.$min($t['streaming_minutes']).' · final '.$min($t['final_minutes'])],
            ['Estimated cost', $usd($t['cost_usd']), 'Priced with the rates in Settings'],
            ['Storage used', $bytes($plan['storage']['used']), $plan['storage']['limit'] === null ? 'No limit' : 'of '.$bytes($plan['storage']['limit'])],
            ['Paired devices', (string) $plan['devices']['used'], $plan['devices']['limit'] === null ? 'No limit' : 'of '.$plan['devices']['limit']],
        ] as [$label, $value, $hint])
            <x-filament::section compact>
                <div style="font-size:.8rem;opacity:.7">{{ $label }}</div>
                <div style="font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $value }}</div>
                <div style="font-size:.75rem;opacity:.6">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Limits this month" :description="$isCurrentMonth ? null : 'Limits always show the current month.'">
        <div style="display:flex;flex-direction:column;gap:1rem">
            @php
                $bars = [['Plan "'.$plan['plan'].'": minutes', $plan['minutes']['used'], $plan['minutes']['limit'], ' min']];
                if ($server !== null && $server['limit_minutes'] !== null) {
                    $bars[] = ['Server cap: minutes (all workspaces)', (float) $server['minutes'], (float) $server['limit_minutes'], ' min'];
                }
                if ($server !== null && $server['limit_usd'] !== null) {
                    $bars[] = ['Server cap: cost (all workspaces)', (float) $server['cost_usd'], (float) $server['limit_usd'], ' US$'];
                }
            @endphp
            @foreach ($bars as [$label, $used, $limit, $unit])
                @php([$pct, $color] = $meter((float) $used, $limit === null ? null : (float) $limit))
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:.85rem">
                        <span>{{ $label }}</span>
                        <span style="font-variant-numeric:tabular-nums">{{ number_format((float) $used, 1) }}{{ $limit === null ? $unit.' · no limit' : ' of '.number_format((float) $limit, $unit === ' US$' ? 2 : 0).$unit }}</span>
                    </div>
                    <div role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($pct ?? 0) }}"
                         style="height:.5rem;border-radius:999px;background:var(--gray-200);overflow:hidden;margin-top:.35rem">
                        <div style="height:100%;width:{{ $pct ?? 0 }}%;background:{{ $color }};border-radius:999px"></div>
                    </div>
                </div>
            @endforeach
            <div style="font-size:.75rem;opacity:.6">
                You get a notification at {{ $server['warn_at_percent'] ?? 80 }}% and at 100%. At the limit, live captions and new transcripts stop until the limit is raised or the month ends.
                @if ($settingsUrl)
                    <a href="{{ $settingsUrl }}" style="text-decoration:underline">Change limits and rates in Settings</a>.
                @endif
            </div>
        </div>
    </x-filament::section>

    @livewire(\App\Filament\Widgets\Usage\DailyUsageChart::class, ['month' => $report['month']], key('usage-chart-'.$report['month']))

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem">
        <x-filament::section heading="By provider" description="Billed minutes: audio minutes × billed channels. Per recorded hour: what an hour of recording cost with that engine (final transcripts).">
            @if ($report['providers'] === [])
                <p style="opacity:.6">No usage in {{ $report['label'] }}.</p>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                        <thead><tr style="text-align:left;opacity:.7"><th style="padding:.5rem .75rem">Provider</th><th style="padding:.5rem .75rem;text-align:right">Streaming</th><th style="padding:.5rem .75rem;text-align:right">Final</th><th style="padding:.5rem .75rem;text-align:right">Recordings</th><th style="padding:.5rem .75rem;text-align:right">Per recorded hour</th><th style="padding:.5rem .75rem;text-align:right">Cost</th></tr></thead>
                        <tbody>
                            @foreach ($report['providers'] as $row)
                                <tr><td style="{{ $cell }}">{{ $row['label'] }}</td><td style="{{ $num }}">{{ $min($row['streaming_minutes']) }}</td><td style="{{ $num }}">{{ $min($row['final_minutes']) }}</td><td style="{{ $num }}">{{ $row['recordings'] }}</td><td style="{{ $num }}">{{ $row['per_hour_usd'] === null ? '—' : '$'.number_format($row['per_hour_usd'], 3) }}</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}@if ($row['check_usd'] > 0)<div style="font-size:.7rem;opacity:.6">incl. {{ $usd($row['check_usd']) }} Double-check</div>@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="By device">
            @if ($report['devices'] === [])
                <p style="opacity:.6">No billable minutes in {{ $report['label'] }}.</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($report['devices'] as $row)
                            <tr><td style="{{ $cell }}">{{ $row['name'] }}</td><td style="{{ $num }}">{{ $min($row['minutes']) }} min</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Top clients">
            @if ($report['clients'] === [])
                <p style="opacity:.6">No billable minutes for a client in {{ $report['label'] }}.</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($report['clients'] as $row)
                            <tr><td style="{{ $cell }}">{{ $row['name'] }}</td><td style="{{ $num }}">{{ $min($row['minutes']) }} min</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        @if ($report['live_translate'] !== [])
            <x-filament::section heading="Live Translate" description="Game and Watch translate on the phone (Soniox real-time). Counted against Live Translate's own budget, not the limit above.">
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($report['live_translate'] as $row)
                            <tr><td style="{{ $cell }}">{{ $row['mode'] }}</td><td style="{{ $num }}">{{ $min($row['minutes']) }} min</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    </div>
</div>
