<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SessionStatus;
use App\Enums\SourceDevice;
use App\Models\CaptureSession;
use App\Services\Usage\UsageSummary;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Counts by source device (icon + label), transcribed minutes in the last 30 days and this month's usage. */
class SourceStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $base = CaptureSession::query()->where('workspace_id', CurrentWorkspace::id());
        $counts = (clone $base)->toBase()->selectRaw('source_device, count(*) as c')->groupBy('source_device')->pluck('c', 'source_device');

        $stats = [];
        foreach (SourceDevice::cases() as $device) {
            $stats[] = Stat::make($device->getLabel(), (int) ($counts[$device->value] ?? 0))
                ->icon($device->getIcon())
                ->description('sessions')
                ->color($device->getColor());
        }

        $ms = (int) (clone $base)->where('status', SessionStatus::Completed->value)
            ->where('started_at', '>=', now()->subDays(30))->sum('duration_ms');
        $stats[] = Stat::make('Transcribed (30 days)', number_format($ms / 60000, 0).' min')
            ->icon(Heroicon::OutlinedClock)
            ->color('primary');

        $stats[] = self::monthStat(app(UsageSummary::class)->toArray((int) CurrentWorkspace::id()));

        return $stats;
    }

    /**
     * Billable usage this calendar month against the monthly limit (A5.2): warning colour from the warn threshold,
     * danger once the limit is reached.
     *
     * @param  array<string, mixed>  $usage  the documented `usage` block (docs/API.md)
     */
    public static function monthStat(array $usage): Stat
    {
        $minutes = (float) ($usage['minutes'] ?? 0);
        $cost = (float) ($usage['cost_usd'] ?? 0);
        $limits = [];
        if (is_numeric($usage['limit_minutes'] ?? null)) {
            $limits[] = number_format((float) $usage['limit_minutes'], 0).' min';
        }
        if (is_numeric($usage['limit_usd'] ?? null)) {
            $limits[] = '$'.number_format((float) $usage['limit_usd'], 2);
        }

        return Stat::make('This month', number_format($minutes, 1).' min · $'.number_format($cost, 2))
            ->description($limits === [] ? 'No monthly limit' : 'of '.implode(' · ', $limits))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color(match (true) {
                (bool) ($usage['limit_reached'] ?? false) => 'danger',
                (bool) ($usage['warn'] ?? false) => 'warning',
                default => 'gray',
            });
    }
}
