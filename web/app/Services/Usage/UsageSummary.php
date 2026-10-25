<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * The `usage` block of GET /api/config and POST /api/usage (docs/API.md): this calendar month, cut in the console's
 * display time zone, for the whole server.
 *
 * Until the usage ledger exists this reports zero usage and no limits.
 */
final class UsageSummary
{
    public const WARN_AT_PERCENT = 80;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array{month: string, time_zone: string, period_start: string, period_end: string, minutes: float,
     *     cost_usd: float, limit_minutes: int|null, limit_usd: float|null, warn_at_percent: int, warn: bool, limit_reached: bool}
     */
    public function toArray(): array
    {
        $zone = $this->settings->displayTimezone();
        $start = Carbon::now($zone)->startOfMonth();
        $end = $start->copy()->addMonthNoOverflow();

        return [
            'month' => $start->format('Y-m'),
            'time_zone' => $zone,
            'period_start' => $start->copy()->utc()->toAtomString(),
            'period_end' => $end->copy()->utc()->toAtomString(),
            'minutes' => 0.0,
            'cost_usd' => 0.0,
            'limit_minutes' => null,
            'limit_usd' => null,
            'warn_at_percent' => self::WARN_AT_PERCENT,
            'warn' => false,
            'limit_reached' => false,
        ];
    }
}
