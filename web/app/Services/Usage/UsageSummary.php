<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\UsageLedgerEntry;
use App\Services\SettingsRepository;

/**
 * The `usage` block of GET /api/config and POST /api/usage (docs/API.md): this calendar month, cut in the console's
 * display time zone, for the whole server. Sums the billable rows of the usage ledger.
 */
final class UsageSummary
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array{month: string, time_zone: string, period_start: string, period_end: string, minutes: float,
     *     cost_usd: float, limit_minutes: int|null, limit_usd: float|null, warn_at_percent: int, warn: bool, limit_reached: bool}
     */
    public function toArray(): array
    {
        $period = UsagePeriod::current($this->settings);
        $totals = UsageLedgerEntry::query()
            ->where('billable', true)
            ->where('occurred_at', '>=', $period['start'])
            ->where('occurred_at', '<', $period['end'])
            ->toBase()
            ->selectRaw('coalesce(sum(billed_ms), 0) as billed_ms, coalesce(sum(cost_usd), 0) as cost_usd')
            ->first();

        $minutes = (float) ($totals->billed_ms ?? 0) / 60000;
        $cost = (float) ($totals->cost_usd ?? 0);
        $limitMinutes = $this->settings->monthlyMinutesLimit();
        $limitUsd = $this->settings->monthlyUsdLimit();
        $warnPercent = $this->settings->warnPercent();

        // Fraction of the tightest limit that is used up; compared unrounded so the flags never disagree with the ledger.
        $used = max(
            $limitMinutes !== null ? $minutes / $limitMinutes : 0.0,
            $limitUsd !== null ? $cost / $limitUsd : 0.0,
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
}
