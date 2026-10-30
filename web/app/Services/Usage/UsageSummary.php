<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Services\LiveTranslate\LiveTranslateBudget;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Support\Carbon;

/**
 * The `usage` block of GET /api/config and POST /api/usage (docs/API.md): this calendar month, cut in the console's
 * display time zone. Sums the billable rows of the usage ledger.
 *
 * Without a workspace: the whole server against the A5 monthly cap (what BudgetGuard enforces). With one (B20):
 * `minutes` / `cost_usd` are that workspace's, and `warn` / `limit_reached` follow the tighter of the server cap and
 * the workspace plan's monthly minutes. Totals never depend on the workspace the request acts for.
 */
final class UsageSummary
{
    public function __construct(private readonly SettingsRepository $settings, private readonly PlanLimits $limits) {}

    /**
     * @return array{month: string, time_zone: string, period_start: string, period_end: string, minutes: float,
     *     cost_usd: float, limit_minutes: int|null, limit_usd: float|null, warn_at_percent: int, warn: bool, limit_reached: bool}
     */
    public function toArray(?int $workspaceId = null): array
    {
        $period = UsagePeriod::current($this->settings);
        [$serverMinutes, $serverCost] = $this->totals($period, null);
        [$minutes, $cost] = $workspaceId === null ? [$serverMinutes, $serverCost] : $this->totals($period, $workspaceId);
        $limitMinutes = $this->settings->monthlyMinutesLimit();
        $limitUsd = $this->settings->monthlyUsdLimit();
        $warnPercent = $this->settings->warnPercent();
        $planMinutes = $workspaceId === null ? null : $this->limits->plan($workspaceId)->monthly_minutes;

        // Fraction of the tightest limit that is used up; compared unrounded so the flags never disagree with the ledger.
        $used = max(
            $limitMinutes !== null ? $serverMinutes / $limitMinutes : 0.0,
            $limitUsd !== null ? $serverCost / $limitUsd : 0.0,
            $planMinutes !== null && $planMinutes > 0 ? $minutes / $planMinutes : 0.0,
        );

        return [
            'month' => $period['month'],
            'time_zone' => $period['zone'],
            'period_start' => $period['start']->toAtomString(),
            'period_end' => $period['end']->toAtomString(),
            'minutes' => round($minutes, 1),
            'cost_usd' => round($cost, 2),
            'limit_minutes' => $limitMinutes,
            'limit_usd' => $limitUsd,
            'warn_at_percent' => $warnPercent,
            'warn' => $used >= $warnPercent / 100,
            'limit_reached' => $used >= 1.0,
        ];
    }

    /**
     * Billed minutes and cost of the billable rows in the period, for one workspace or the whole server.
     *
     * @param  array{start: Carbon, end: Carbon}  $period
     * @return array{0: float, 1: float}
     */
    private function totals(array $period, ?int $workspaceId): array
    {
        $totals = UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->where('billable', true)
            // Live Translate has its own monthly budget (LiveTranslateBudget): a game night never stops call transcripts.
            ->whereNotIn('use_case', LiveTranslateBudget::useCases())
            ->where('occurred_at', '>=', $period['start'])
            ->where('occurred_at', '<', $period['end'])
            ->toBase()
            ->selectRaw('coalesce(sum(billed_ms), 0) as billed_ms, coalesce(sum(cost_usd), 0) as cost_usd')
            ->first();

        return [(float) ($totals->billed_ms ?? 0) / 60000, (float) ($totals->cost_usd ?? 0)];
    }
}
