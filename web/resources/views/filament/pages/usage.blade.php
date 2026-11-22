{{-- A tile's value is not a translated sentence's :value, so the translator (B8) can't isolate it: it is isolated here in right to left ("68.0 B"). --}}
@php
    $iso = fn (mixed $value): string => \App\Filament\Support\ConsoleV2::rtl() ? "\u{2068}".$value."\u{2069}" : (string) $value;
@endphp
@php
    $t = $report['totals'];
    $min = fn (float $m): string => number_format($m, $m < 10 ? 1 : 0);
    $usd = fn (float $c): string => '$'.number_format($c, 2);
    $bytes = fn (?int $b): string => $b === null ? __('no limit') : \Illuminate\Support\Number::fileSize($b, precision: 1);
    $meter = function (float $used, ?float $limit): array {
        $pct = $limit !== null && $limit > 0 ? min(100, $used / $limit * 100) : null;
        $color = $pct === null ? 'var(--gray-400)' : ($pct >= 100 ? 'var(--danger-500)' : ($pct >= 80 ? 'var(--warning-500)' : 'var(--primary-500)'));

        return [$pct, $color];
    };
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $num = $cell.'text-align:right;font-variant-numeric:tabular-nums;';
    // Every engine together (since v1.8.1, W-001).
    $all = $report['all'];
    $anyUsage = $all['streaming_minutes'] + $all['final_minutes'] + $all['cost_usd'] > 0 || $all['recordings'] > 0;
    // One cost on the page: every engine together ($all), in the tile and in Totals. A Double-check share is named only
    // when it shows as at least a cent.
    $shows = fn (float $c): bool => round($c, 2) > 0;
@endphp

<div class="el-usage" style="display:flex;flex-direction:column;gap:1.5rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem">
        @foreach ([
            [$ownOnly ? __('Your billable minutes') : __('Billable minutes'), $min($t['minutes']), __('Streaming :streaming · final :final', ['streaming' => $min($t['streaming_minutes']), 'final' => $min($t['final_minutes'])])],
            [__('Estimated cost'), $usd($all['cost_usd']), __('Priced with the rates in Settings')],
            [__('Storage used'), $bytes($plan['storage']['used']), $plan['storage']['limit'] === null ? __('No limit') : __('of :limit', ['limit' => $bytes($plan['storage']['limit'])])],
            [__('Signed-in devices'), (string) $plan['devices']['used'], $plan['devices']['limit'] === null ? __('No limit') : __('of :limit', ['limit' => $plan['devices']['limit']])],
        ] as [$label, $value, $hint])
            <x-filament::section compact>
                <div style="font-size:.8rem;opacity:.7">{{ $label }}</div>
                <div style="font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $iso($value) }}</div>
                <div style="font-size:.75rem;opacity:.6">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="__('Limits this month')" :description="$isCurrentMonth ? null : __('Limits always show the current month.')">
        <div style="display:flex;flex-direction:column;gap:1rem">
            @php
                $bars = [[__('Plan ":plan": minutes', ['plan' => $plan['plan']]), $plan['minutes']['used'], $plan['minutes']['limit'], ' min']];
                if ($server !== null && $server['limit_minutes'] !== null) {
                    $bars[] = [__('Server cap: minutes (all workspaces)'), (float) $server['minutes'], (float) $server['limit_minutes'], ' min'];
                }
                if ($server !== null && $server['limit_usd'] !== null) {
                    $bars[] = [__('Server cap: cost (all workspaces)'), (float) $server['cost_usd'], (float) $server['limit_usd'], ' US$'];
                }
            @endphp
            @foreach ($bars as [$label, $used, $limit, $unit])
                @php([$pct, $color] = $meter((float) $used, $limit === null ? null : (float) $limit))
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:.85rem">
                        <span>{{ $label }}</span>
                        @php($amount = fn (float $n, int $places): string => $unit === ' US$' ? number_format($n, $places).' US$' : __(':n min', ['n' => number_format($n, $places)]))
                        {{-- "88.0 of 100 min": the unit once, after the limit; "0.0 min · no limit" without one. --}}
                        <span style="font-variant-numeric:tabular-nums">{{ $limit === null ? __(':used · no limit', ['used' => $amount((float) $used, 1)]) : __(':used of :limit', ['used' => number_format((float) $used, 1), 'limit' => $amount((float) $limit, $unit === ' US$' ? 2 : 0)]) }}</span>
                    </div>
                    <div role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($pct ?? 0) }}"
                         style="height:.5rem;border-radius:999px;background:var(--gray-200);overflow:hidden;margin-top:.35rem">
                        <div style="height:100%;width:{{ $pct ?? 0 }}%;background:{{ $color }};border-radius:999px"></div>
                    </div>
                </div>
            @endforeach
            <div style="font-size:.75rem;opacity:.6">
                {{ __('Owners get a notification at :warn% and at 100%. At the limit, live captions and new transcripts stop until the limit is raised or the month ends.', ['warn' => $server['warn_at_percent'] ?? 80]) }}
                @if ($settingsUrl)
                    <a href="{{ $settingsUrl }}" style="text-decoration:underline">{{ __('Change limits and rates in Settings') }}</a>
                @endif
            </div>
        </div>
    </x-filament::section>

    @if ($people !== null)
        @include('filament.pages.usage-people', ['people' => $people, 'description' => __('Cost as each use was priced, like the totals above. Never what anyone recorded.')])
    @endif

    @livewire(\App\Filament\Widgets\Usage\DailyUsageChart::class, ['month' => $report['month']], key('usage-chart-'.$report['month']))

    @include('filament.pages.usage-trend', ['trend' => $trend])

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem">
        {{-- Since v1.8.1 (W-001) every engine together: which provider did what is for the admin panel only. --}}
        <x-filament::section :heading="__('Totals')" :description="__('Billed minutes: audio minutes × billed channels. Per recorded hour: what an hour of recording cost (final transcripts).')">
            @if (! $anyUsage)
                <p style="opacity:.6">{{ __('No usage in :month.', ['month' => $report['label']]) }}</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse" data-usage-totals>
                    <tbody>
                        <tr><td style="{{ $cell }}">{{ __('Streaming') }}</td><td style="{{ $num }}">{{ __(':n min', ['n' => $min($all['streaming_minutes'])]) }}</td></tr>
                        <tr><td style="{{ $cell }}">{{ __('Final') }}</td><td style="{{ $num }}">{{ __(':n min', ['n' => $min($all['final_minutes'])]) }}</td></tr>
                        <tr><td style="{{ $cell }}">{{ __('Recordings') }}</td><td style="{{ $num }}">{{ number_format($all['recordings']) }}</td></tr>
                        <tr><td style="{{ $cell }}">{{ __('Per recorded hour') }}</td><td style="{{ $num }}">{{ $all['per_hour_usd'] === null ? '—' : '$'.number_format($all['per_hour_usd'], 3) }}</td></tr>
                        <tr><td style="{{ $cell }}">{{ __('Cost') }}</td><td style="{{ $num }}">{{ $usd($all['cost_usd']) }}@if ($shows($all['check_usd']))<div style="font-size:.7rem;opacity:.6">{{ __('incl. :amount Double-check', ['amount' => $usd($all['check_usd'])]) }}</div>@endif</td></tr>
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('By device')">
            @if ($report['devices'] === [])
                <p style="opacity:.6">{{ __('No billable minutes in :month.', ['month' => $report['label']]) }}</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($report['devices'] as $row)
                            <tr><td style="{{ $cell }}">{{ $row['name'] }}</td><td style="{{ $num }}">{{ __(':n min', ['n' => $min($row['minutes'])]) }}</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Top projects')">
            @if ($report['clients'] === [])
                <p style="opacity:.6">{{ __('No billable minutes for a project in :month.', ['month' => $report['label']]) }}</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($report['clients'] as $row)
                            <tr><td style="{{ $cell }}">{{ $row['name'] }}</td><td style="{{ $num }}">{{ __(':n min', ['n' => $min($row['minutes'])]) }}</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Per language')" :description="__('What each language\'s recordings cost this month: transcription, the double-check of short notes and the views.')">
            @if (($report['languages'] ?? []) === [])
                <p style="opacity:.6">{{ __('No billable minutes in :month.', ['month' => $report['label']]) }}</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($report['languages'] as $row)
                            <tr><td style="{{ $cell }}">{{ $row['label'] }}</td><td style="{{ $num }}">{{ $row['recordings'] === 1 ? __(':n recording', ['n' => 1]) : __(':n recordings', ['n' => $row['recordings']]) }}</td><td style="{{ $num }}">{{ __(':n min', ['n' => $min($row['minutes'])]) }}</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}@if ($shows($row['check_usd'])) <span style="opacity:.6">{{ __('(check :amount)', ['amount' => $usd($row['check_usd'])]) }}</span>@endif</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        @if ($report['live_translate'] !== [])
            <x-filament::section :heading="__('Live Translate')" :description="__('Game and Watch translate on the phone. Counted against Live Translate\'s own budget, not the limit above.')">
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($report['live_translate'] as $row)
                            <tr><td style="{{ $cell }}">{{ $row['mode'] }}</td><td style="{{ $num }}">{{ __(':n min', ['n' => $min($row['minutes'])]) }}</td><td style="{{ $num }}">{{ $usd($row['cost_usd']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    </div>
</div>
