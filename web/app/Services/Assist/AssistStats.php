<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Models\AssistSession;
use App\Models\AssistUsage;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Live Assist's numbers for the admin panel (v1.9 L8), from the usage records and the sessions alone, never a word
 * of content: cost and calls per model and per plan, cost per assist-hour per mode, the share of input read from the
 * prompt cache, how the calls ended, and p50 / p95 of each latency stage the relay measures.
 */
final class AssistStats
{
    /** The relay's latency stages (services/assist-relay/src/protocol.rs Latency), in the order they happen. */
    public const STAGES = [
        'end_of_turn_to_request_ms' => 'End of turn → request',
        'request_to_first_token_ms' => 'Request → first token',
        'end_of_turn_to_first_token_ms' => 'End of turn → first token',
        'relay_overhead_ms' => 'Relay overhead',
        'total_ms' => 'Whole suggestion',
    ];

    /** Kinds the person waits for on screen; the background calls (facts, summary) and the report are not timed. */
    public const TIMED_KINDS = ['suggestion', 'answer', 'help_now', 'regenerate', 'shorter', 'formal', 'translate', 'upgrade', 'practice_question'];

    /** At most this many rows are read for the percentiles (the newest). */
    public const MAX_TIMED_ROWS = 50000;

    /**
     * The numbers of [$from, $to).
     *
     * @return array<string, mixed>
     */
    public function between(Carbon $from, Carbon $to): array
    {
        $usage = fn () => AssistUsage::query()->where('occurred_at', '>=', $from->copy()->utc())->where('occurred_at', '<', $to->copy()->utc());
        $tokens = 'SUM(input_tokens) AS input, SUM(cached_tokens) AS cached, SUM(written_tokens) AS written, SUM(output_tokens) AS output, SUM(cost_usd) AS cost, COUNT(*) AS calls';

        $models = $usage()->selectRaw("model, provider, {$tokens}")->groupBy('model', 'provider')->toBase()->get()
            ->map(fn ($row): array => ['model' => (string) $row->model, 'provider' => (string) $row->provider, ...self::sums($row)])
            ->sortByDesc('cost_usd')->values()->all();

        // Assist time per plan and per mode: the sessions that started in the period, Practice apart from calls.
        $hours = [];
        AssistSession::query()->withoutGlobalScopes()->withTrashed()->where('started_at', '>=', $from->copy()->utc())->where('started_at', '<', $to->copy()->utc())
            ->select(['id', 'plan', 'mode', 'practice', 'started_at', 'ended_at', 'seconds_used'])
            ->each(function (AssistSession $s) use (&$hours): void {
                foreach (['plan' => $s->plan ?? 'unknown', 'mode' => $s->practice ? 'practice' : $s->mode] as $by => $key) {
                    $hours[$by][$key]['sessions'] = ($hours[$by][$key]['sessions'] ?? 0) + 1;
                    $hours[$by][$key]['seconds'] = ($hours[$by][$key]['seconds'] ?? 0) + $s->seconds();
                }
            });

        $plans = [];
        foreach ($usage()->selectRaw("COALESCE(plan, 'unknown') AS plan, {$tokens}")->groupBy('plan')->toBase()->get() as $row) {
            $plans[(string) $row->plan] = self::sums($row);
        }
        // By the sold plan (Business and Personal apart), with the Live Assist tier it gives (free, pro, ultra).
        $names = Plan::query()->pluck('name', 'key')->all();
        $byPlan = [];
        foreach (array_unique([...array_keys($plans), ...array_keys($hours['plan'] ?? [])]) as $plan) {
            $byPlan[] = self::perHour([
                'plan' => (string) $plan,
                'name' => $plan === 'unknown' ? 'Not recorded' : (string) ($names[$plan] ?? $plan),
                'tier' => $plan === 'unknown' ? null : AssistPlans::config((string) $plan)['claim'],
            ], $plans[$plan] ?? self::sums(null), $hours['plan'][$plan] ?? []);
        }
        usort($byPlan, fn (array $a, array $b): int => $b['cost_usd'] <=> $a['cost_usd']);

        // Cost per mode follows the session's mode (Practice apart), so it sits with that mode's hours.
        $modes = [];
        foreach ($usage()->join('assist_sessions', 'assist_sessions.id', '=', 'assist_usage.assist_session_id')
            ->selectRaw("CASE WHEN assist_sessions.practice THEN 'practice' ELSE assist_sessions.mode END AS session_mode, SUM(assist_usage.cost_usd) AS cost, COUNT(*) AS calls")
            ->groupBy('session_mode')->toBase()->get() as $row) {
            $modes[(string) $row->session_mode] = ['cost_usd' => round((float) $row->cost, 4), 'calls' => (int) $row->calls];
        }
        $byMode = [];
        foreach ([...array_keys((array) config('assist.modes', [])), 'practice'] as $mode) {
            if (isset($modes[$mode]) || isset($hours['mode'][$mode])) {
                $byMode[] = self::perHour(['mode' => $mode], ($modes[$mode] ?? []) + self::sums(null), $hours['mode'][$mode] ?? []);
            }
        }

        $outcomes = $usage()->whereIn('kind', self::TIMED_KINDS)->selectRaw("COALESCE(outcome, 'unknown') AS outcome, COUNT(*) AS calls")->groupBy('outcome')->pluck('calls', 'outcome')
            ->map(fn ($n): int => (int) $n)->sortDesc()->all();
        $timed = array_sum($outcomes);

        $totals = self::sums($usage()->selectRaw($tokens)->toBase()->first());

        return [
            'totals' => $totals + [
                'sessions' => (int) array_sum(array_column($hours['plan'] ?? [], 'sessions')),
                'hours' => round(array_sum(array_column($hours['plan'] ?? [], 'seconds')) / 3600, 2),
            ],
            'models' => $models,
            'plans' => $byPlan,
            'modes' => $byMode,
            'outcomes' => $outcomes,
            'error_share' => $timed > 0 ? round(($outcomes['error'] ?? 0) * 100 / $timed, 1) : null,
            'latency' => $this->latency($usage()),
        ];
    }

    /**
     * p50 and p95 of each stage, overall and per model, for the calls a person waits for.
     *
     * @param  Builder<AssistUsage>  $usage
     * @return array{all: array<string, array{p50: float|null, p95: float|null, n: int}>, models: array<string, array<string, array{p50: float|null, p95: float|null, n: int}>>, rows: int}
     */
    private function latency($usage): array
    {
        $values = ['all' => [], 'models' => []];
        $rows = 0;
        $usage->whereIn('kind', self::TIMED_KINDS)->whereNotNull('latency')->orderByDesc('occurred_at')->limit(self::MAX_TIMED_ROWS)
            ->select(['model', 'latency'])->each(function (AssistUsage $row) use (&$values, &$rows): void {
                $rows++;
                foreach (array_keys(self::STAGES) as $stage) {
                    $ms = $row->latency[$stage] ?? null;
                    if (is_numeric($ms) && $ms >= 0) {
                        $values['all'][$stage][] = (float) $ms;
                        $values['models'][$row->model][$stage][] = (float) $ms;
                    }
                }
            });
        $summary = fn (array $byStage): array => array_map(fn (array $list): array => self::percentiles($list), array_intersect_key($byStage + array_fill_keys(array_keys(self::STAGES), []), self::STAGES));

        return [
            'all' => $summary($values['all']),
            'models' => array_map($summary, $values['models']),
            'rows' => $rows,
        ];
    }

    /**
     * Nearest-rank p50 and p95 of [$values].
     *
     * @param  list<float>  $values
     * @return array{p50: float|null, p95: float|null, n: int}
     */
    public static function percentiles(array $values): array
    {
        if ($values === []) {
            return ['p50' => null, 'p95' => null, 'n' => 0];
        }
        sort($values);
        $at = fn (int $p): float => round($values[max(0, (int) ceil($p / 100 * count($values)) - 1)], 1);

        return ['p50' => $at(50), 'p95' => $at(95), 'n' => count($values)];
    }

    /** @return array{calls: int, input_tokens: int, cached_tokens: int, written_tokens: int, output_tokens: int, cost_usd: float, cache_hit_percent: float|null} */
    private static function sums(?object $row): array
    {
        $input = (int) ($row->input ?? 0);
        $cached = (int) ($row->cached ?? 0);
        $written = (int) ($row->written ?? 0);
        $all = $input + $cached + $written;

        return [
            'calls' => (int) ($row->calls ?? 0),
            'input_tokens' => $input, 'cached_tokens' => $cached, 'written_tokens' => $written, 'output_tokens' => (int) ($row->output ?? 0),
            'cost_usd' => round((float) ($row->cost ?? 0), 4),
            // The share of the input read from the prompt cache (the relay's target: 80% after the third suggestion).
            'cache_hit_percent' => $all > 0 ? round($cached * 100 / $all, 1) : null,
        ];
    }

    /**
     * @param  array<string, string|null>  $key
     * @param  array<string, mixed>  $cost
     * @param  array{sessions?: int, seconds?: int}  $time
     * @return array<string, mixed>
     */
    private static function perHour(array $key, array $cost, array $time): array
    {
        $hours = ($time['seconds'] ?? 0) / 3600;

        return $key + [
            'sessions' => $time['sessions'] ?? 0,
            'hours' => round($hours, 2),
            'calls' => (int) ($cost['calls'] ?? 0),
            'cost_usd' => round((float) ($cost['cost_usd'] ?? 0), 4),
            'cost_per_hour_usd' => $hours > 0 ? round((float) ($cost['cost_usd'] ?? 0) / $hours, 4) : null,
            'cache_hit_percent' => $cost['cache_hit_percent'] ?? null,
        ];
    }
}
