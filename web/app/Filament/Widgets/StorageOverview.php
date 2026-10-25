<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\RetentionPolicy;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** The storage meter (plan B17): what the recordings take and how full the server disk is. */
class StorageOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Storage';

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $policy = app(RetentionPolicy::class);
        $storage = $policy->storage((int) CurrentWorkspace::id());
        $stats = [
            Stat::make('Recordings', Number::fileSize($storage['recordings_bytes'], precision: 1))
                ->description($storage['recordings_count'].' files · deleted after '.$policy->daysFor(null).' days'),
        ];
        if ($storage['disk_total_bytes'] !== null && $storage['disk_free_bytes'] !== null && $storage['disk_total_bytes'] > 0) {
            $used = 1 - $storage['disk_free_bytes'] / $storage['disk_total_bytes'];
            $stats[] = Stat::make('Server disk', Number::percentage($used * 100, precision: 0).' used')
                ->description(Number::fileSize($storage['disk_free_bytes'], precision: 1).' free of '.Number::fileSize($storage['disk_total_bytes'], precision: 1))
                ->color($used > 0.9 ? 'danger' : ($used > 0.75 ? 'warning' : 'success'));
        }

        return $stats;
    }
}
