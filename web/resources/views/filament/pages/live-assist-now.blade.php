{{-- Live Assist right now, for the super admin (App\Services\Assist\AssistMonitor, plan B4). States, times and numbers only. --}}
@php
    $r = $now['relay'];
    $s = $now['sessions'];
    $h = $now['hour'];
    $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
    $at = fn ($t): string => $t === null ? '—' : $t->copy()->setTimezone($zone)->format('j M, H:i');
    $ago = fn ($t): string => $t === null ? __('never') : $t->diffForHumans(['parts' => 1]);
    $pct = fn (?float $p): string => $p === null ? '—' : rtrim(rtrim(number_format($p, 2), '0'), '.').'%';
    $ms = fn (?float $v): string => $v === null ? '—' : number_format($v, $v < 100 ? 1 : 0).' ms';
    [$label, $color] = match ($r['status']) {
        'up' => [__('assist_context.relay_up'), 'success'],
        'down' => [__('assist_context.relay_down'), 'danger'],
        'not_set' => [__('Not set up'), 'gray'],
        default => [__('Not checked yet'), 'warning'],
    };
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
@endphp
<div wire:poll.30s style="display:flex;flex-direction:column;gap:1.5rem" data-live-assist-now>
    <x-filament::section :heading="__('Relay')" :description="$r['host'] !== null ? $r['host'].' · '.$r['connection'] : $r['connection']" data-assist-relay>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1rem;align-items:start">
            <div>
                <div style="font-size:.8rem;opacity:.7">{{ __('Now') }}</div>
                <x-filament::badge :color="$color" data-relay-status="{{ $r['status'] }}">{{ $label }}</x-filament::badge>
                @if ($r['latest'] !== null)
                    <div style="font-size:.75rem;opacity:.7;margin-top:.25rem">
                        {{ __('checked :ago', ['ago' => $ago($r['latest']['at'])]) }}@if ($r['latest']['ok']), {{ $r['latest']['ms'] }} ms{{ $r['latest']['version'] !== null ? ', '.__('version :version', ['version' => $r['latest']['version']]) : '' }}@endif
                    </div>
                    @if (! $r['latest']['ok'] && $r['latest']['error'] !== null)
                        <div style="font-size:.75rem;margin-top:.25rem">{{ $r['latest']['error'] }}</div>
                    @endif
                @elseif ($r['status'] === 'not_set')
                    <div style="font-size:.75rem;opacity:.7;margin-top:.25rem">{{-- Escaped but for quotes, so the English stays byte for byte as it was. --}}{!! htmlspecialchars(__("Set the relay's address in Settings → Live Assist."), ENT_NOQUOTES) !!}</div>
                @endif
            </div>
            <div>
                <div style="font-size:.8rem;opacity:.7">{{ __('Uptime') }}</div>
                <div style="font-size:1.25rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $pct($r['uptime_24h']) }}</div>
                <div style="font-size:.75rem;opacity:.7">{{ __('24 h (:checks checks) · 7 days :uptime', ['checks' => number_format($r['checks_24h']), 'uptime' => $pct($r['uptime_7d'])]) }}</div>
            </div>
            <div>
                <div style="font-size:.8rem;opacity:.7">{{ __('Last call from the relay') }}</div>
                <div style="font-size:1.25rem;font-weight:600">{{ $ago($r['last_contact']) }}</div>
                <div style="font-size:.75rem;opacity:.7">{{ __('it asks for its settings every 10 minutes') }}</div>
            </div>
        </div>
        @if ($r['host'] !== null && ! $r['secret_set'])
            <p style="margin:.75rem 0 0;font-size:.85rem" data-relay-warning="secret">{{ __('The relay secret is missing: API Credentials → assist_relay.') }}</p>
        @endif
        @if ($r['host'] !== null && $r['checks_stale'])
            <p style="margin:.75rem 0 0;font-size:.85rem" data-relay-warning="stale">{{ __('No scheduled check in the last 15 minutes: is the scheduler running (cron, schedule:run)?') }}</p>
        @endif
        @if ($r['outages'] !== [])
            <div style="overflow-x:auto;margin-top:1rem"><table style="width:100%;font-size:.85rem;border-collapse:collapse" data-relay-outages>
                <caption style="text-align:left;font-size:.8rem;opacity:.7;padding-bottom:.25rem">{{ __('Outages in the last 7 days') }}</caption>
                <thead><tr style="text-align:left;opacity:.7"><th scope="col" style="padding:.5rem .75rem">{{ __('assist_context.outage_from') }}</th><th scope="col" style="padding:.5rem .75rem">{{ __('assist_context.outage_to') }}</th>
                    <th scope="col" style="padding:.5rem .75rem;text-align:right">{{ __('Length') }}</th><th scope="col" style="padding:.5rem .75rem">{{ __('assist_context.outage_why') }}</th></tr></thead>
                <tbody>
                @foreach ($r['outages'] as $o)
                    <tr><td style="{{ $cell }}">{{ $at($o['from']) }}</td><td style="{{ $cell }}">{{ $o['to'] === null ? __('still down') : $at($o['to']) }}</td>
                        <td style="{{ $cell }}text-align:right;font-variant-numeric:tabular-nums">{{ $o['minutes'] < 1 ? __('under a minute') : __(':n min', ['n' => $o['minutes']]) }}</td>
                        <td style="{{ $cell }}">{{ $o['error'] ?? '—' }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @elseif ($r['host'] !== null && $r['uptime_7d'] !== null)
            <p style="margin:.75rem 0 0;font-size:.85rem;opacity:.7">{{ __('No outage in the last 7 days.') }}</p>
        @endif
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1rem" data-assist-now>
        @foreach ([
            [__('Sessions now'), number_format($s['active']), __(':relay relay · :shared shared hosting · :practice practice', ['relay' => number_format($s['active_relay']), 'shared' => number_format($s['active_http']), 'practice' => number_format($s['active_practice'])])],
            [__('Started'), __(':count in the last hour', ['count' => number_format($s['started_hour'])]), __(':count today', ['count' => number_format($s['started_today'])])],
            [__('First word, last hour'), $ms($h['first_word']['p50']), __('p95 :time · :count suggestions', ['time' => $ms($h['first_word']['p95']), 'count' => number_format($h['suggestions'])])],
            [__('Relay overhead, last hour'), $ms($h['overhead']['p95']), __('p95 (target ≤ 30 ms)')],
            [__('Errors, last hour'), $pct($h['error_share']), __(':amount spent', ['amount' => '$'.number_format($h['cost_usd'], 4)])],
        ] as [$label, $value, $hint])
            <x-filament::section compact>
                <div style="font-size:.8rem;opacity:.7">{{ $label }}</div>
                <div style="font-size:1.25rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $value }}</div>
                <div style="font-size:.75rem;opacity:.7">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>
</div>
