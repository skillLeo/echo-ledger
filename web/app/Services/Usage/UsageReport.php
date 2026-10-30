<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Services\LiveTranslate\LiveTranslateBudget;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * The usage page (B20): one month of one workspace, read only from the usage ledger, so every number adds up to the
 * ledger exactly. Minutes are billed minutes (duration × billed channels); cost is the `cost_usd` stored with each row,
 * priced from the rates in Settings when the row was written. Months are cut in the console's display time zone.
 */
final class UsageReport
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, string> `Y-m` => "October 2026", newest first */
    public function months(int $count = 12): array
    {
        $start = Carbon::now($this->settings->displayTimezone())->startOfMonth();
        $months = [];
        for ($i = 0; $i < $count; $i++) {
            $month = $start->copy()->subMonthsNoOverflow($i);
            $months[$month->format('Y-m')] = $month->format('F Y');
        }

        return $months;
    }

    /**
     * @return array{month: string, label: string,
     *     totals: array{minutes: float, cost_usd: float, streaming_minutes: float, final_minutes: float, free_minutes: float},
     *     providers: list<array{provider: string, label: string, streaming_minutes: float, final_minutes: float, cost_usd: float, recordings: int, per_hour_usd: float|null, check_usd: float}>,
     *     devices: list<array{name: string, minutes: float, cost_usd: float}>,
     *     clients: list<array{name: string, minutes: float, cost_usd: float}>,
     *     live_translate: list<array{mode: string, minutes: float, cost_usd: float}>,
     *     daily: array{labels: list<string>, minutes: list<float>}}
     */
    public function forMonth(int $workspaceId, string $month): array
    {
        $zone = $this->settings->displayTimezone();
        $start = preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $zone)
            : Carbon::now($zone)->startOfMonth();
        $end = $start->copy()->addMonthNoOverflow();

        $totals = ['minutes' => 0.0, 'cost_usd' => 0.0, 'streaming_minutes' => 0.0, 'final_minutes' => 0.0, 'free_minutes' => 0.0];
        $providers = [];
        $devices = [];
        $sessions = [];
        $modes = [];
        $days = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $days[$day->format('Y-m-d')] = 0.0;
        }

        $rows = UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)
            ->where('occurred_at', '>=', $start->copy()->utc())->where('occurred_at', '<', $end->copy()->utc())
            ->toBase()->select(['provider', 'use_case', 'source', 'duration_ms', 'billed_ms', 'cost_usd', 'billable', 'device_id', 'capture_session_id', 'model', 'occurred_at'])
            ->orderBy('occurred_at')->cursor();
        foreach ($rows as $row) {
            $minutes = (int) $row->billed_ms / 60000;
            $cost = (float) $row->cost_usd;
            if (in_array($row->use_case, LiveTranslateBudget::useCases(), true)) {
                $modes[$row->use_case] = [($modes[$row->use_case][0] ?? 0.0) + $minutes, ($modes[$row->use_case][1] ?? 0.0) + $cost];
            }
            $kind = $row->source === UsageLedgerEntry::SOURCE_DEVICE ? 'streaming_minutes' : 'final_minutes';
            $providers[$row->provider] ??= ['streaming_minutes' => 0.0, 'final_minutes' => 0.0, 'cost_usd' => 0.0, 'check_usd' => 0.0, 'final_usd' => 0.0, 'audio_ms' => 0, 'sessions' => []];
            $providers[$row->provider][$kind] += $minutes;
            $providers[$row->provider]['cost_usd'] += $cost;
            if ($row->model === 'double-check') {
                $providers[$row->provider]['check_usd'] += $cost;
            } elseif ($kind === 'final_minutes') {
                // What a recorded hour costs with this engine: final transcripts only, per hour of audio (not per channel).
                $providers[$row->provider]['final_usd'] += $cost;
                $providers[$row->provider]['audio_ms'] += (int) $row->duration_ms;
                if ($row->capture_session_id !== null) {
                    $providers[$row->provider]['sessions'][$row->capture_session_id] = true;
                }
            }
            if (! (bool) $row->billable) {
                $totals['free_minutes'] += $minutes;

                continue;
            }
            $totals['minutes'] += $minutes;
            $totals['cost_usd'] += $cost;
            $totals[$kind] += $minutes;
            $key = (string) ($row->device_id ?? '');
            $devices[$key] = [($devices[$key][0] ?? 0.0) + $minutes, ($devices[$key][1] ?? 0.0) + $cost];
            if ($row->capture_session_id !== null) {
                $sessions[$row->capture_session_id] = [($sessions[$row->capture_session_id][0] ?? 0.0) + $minutes, ($sessions[$row->capture_session_id][1] ?? 0.0) + $cost];
            }
            $day = Carbon::parse($row->occurred_at, 'UTC')->setTimezone($zone)->format('Y-m-d');
            if (array_key_exists($day, $days)) {
                $days[$day] += $minutes;
            }
        }

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'totals' => $totals,
            'providers' => $this->providerRows($providers),
            'devices' => $this->deviceRows($workspaceId, $devices),
            'clients' => $this->clientRows($workspaceId, $sessions),
            // Since v1.3: Game and Watch translate per mode (they have their own budget).
            'live_translate' => array_map(
                fn (string $key, array $v): array => ['mode' => SttUseCase::from($key)->getLabel(), 'minutes' => round($v[0], 1), 'cost_usd' => round($v[1], 2)],
                array_keys($modes),
                array_values($modes),
            ),
            'daily' => [
                'labels' => array_map(fn (string $d): string => Carbon::parse($d)->format('j'), array_keys($days)),
                'minutes' => array_map(fn (float $m): float => round($m, 1), array_values($days)),
            ],
        ];
    }

    /**
     * Per engine: minutes, cost, the recordings it transcribed, what one recorded hour cost with it (final transcripts,
     * since v1.2) and the part of the cost that was Double-check.
     *
     * @param  array<string, array{streaming_minutes: float, final_minutes: float, cost_usd: float, check_usd: float, final_usd: float, audio_ms: int, sessions: array<string, true>}>  $providers
     * @return list<array{provider: string, label: string, streaming_minutes: float, final_minutes: float, cost_usd: float, recordings: int, per_hour_usd: float|null, check_usd: float}>
     */
    private function providerRows(array $providers): array
    {
        $rows = [];
        foreach ($providers as $key => $v) {
            $rows[] = [
                'provider' => $key,
                'label' => SttProviderKey::tryFrom($key)?->getLabel() ?? ($key === 'claude' ? 'Claude (Show as)' : $key),
                'streaming_minutes' => $v['streaming_minutes'],
                'final_minutes' => $v['final_minutes'],
                'cost_usd' => $v['cost_usd'],
                'recordings' => count($v['sessions']),
                'per_hour_usd' => $v['audio_ms'] > 0 ? round($v['final_usd'] / ($v['audio_ms'] / 3_600_000), 3) : null,
                'check_usd' => $v['check_usd'],
            ];
        }
        usort($rows, fn (array $a, array $b): int => ($b['streaming_minutes'] + $b['final_minutes']) <=> ($a['streaming_minutes'] + $a['final_minutes']));

        return $rows;
    }

    /**
     * @param  array<string, array{0: float, 1: float}>  $devices
     * @return list<array{name: string, minutes: float, cost_usd: float}>
     */
    private function deviceRows(int $workspaceId, array $devices): array
    {
        $names = Device::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)
            ->whereIn('id', array_filter(array_keys($devices)))->pluck('name', 'id');
        $rows = [];
        foreach ($devices as $id => [$minutes, $cost]) {
            $rows[] = ['name' => $id === '' ? 'Server (voice notes, re-transcribe)' : ($names[$id] ?? 'A removed device'), 'minutes' => $minutes, 'cost_usd' => $cost];
        }
        usort($rows, fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);

        return $rows;
    }

    /**
     * Top five clients by billed minutes; recordings without a client are left out.
     *
     * @param  array<string, array{0: float, 1: float}>  $sessions
     * @return list<array{name: string, minutes: float, cost_usd: float}>
     */
    private function clientRows(int $workspaceId, array $sessions): array
    {
        $clientOf = CaptureSession::withTrashed()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)
            ->whereIn('id', array_keys($sessions))->whereNotNull('client_id')->pluck('client_id', 'id');
        $names = Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)
            ->whereIn('id', $clientOf->unique()->values())->pluck('name', 'id');
        $clients = [];
        foreach ($sessions as $session => [$minutes, $cost]) {
            $client = $clientOf[$session] ?? null;
            if ($client === null || ! isset($names[$client])) {
                continue;
            }
            $clients[$client] = ['name' => $names[$client], 'minutes' => ($clients[$client]['minutes'] ?? 0.0) + $minutes, 'cost_usd' => ($clients[$client]['cost_usd'] ?? 0.0) + $cost];
        }
        usort($clients, fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);

        return array_slice($clients, 0, 5);
    }
}
