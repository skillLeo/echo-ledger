<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/** The calendar month usage is counted in: cut in the console's display time zone, bounds as UTC instants. */
final class UsagePeriod
{
    /** @return array{month: string, zone: string, start: Carbon, end: Carbon} `end` is exclusive */
    public static function current(SettingsRepository $settings): array
    {
        $zone = $settings->displayTimezone();
        $start = Carbon::now($zone)->startOfMonth();
        $end = $start->copy()->addMonthNoOverflow();

        return [
            'month' => $start->format('Y-m'),
            'zone' => $zone,
            'start' => $start->copy()->utc(),
            'end' => $end->copy()->utc(),
        ];
    }
}
