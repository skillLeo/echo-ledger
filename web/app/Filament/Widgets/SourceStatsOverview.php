<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SessionStatus;
use App\Enums\SourceDevice;
use App\Models\CaptureSession;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Counts by source device (icon + label) plus transcribed minutes in the last 30 days. */
class SourceStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $base = CaptureSession::query()->where('user_id', auth()->id());
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

        return $stats;
    }
}
