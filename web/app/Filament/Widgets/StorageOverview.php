<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\RetentionPolicy;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** The storage meter (plan B17): what the recordings take and how full the server disk is. */
class StorageOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    /** Since v1.5 the dashboard of the whole console; members and viewers see their library instead. */
    public static function canView(): bool
    {
        return ConsoleAccess::full();
    }

    protected ?string $heading = 'Storage';

    /** Since v2.0 in the console's language (English is unchanged). */
    protected function getHeading(): ?string
    {
        return __((string) $this->heading);
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $policy = app(RetentionPolicy::class);
        $storage = $policy->storage((int) CurrentWorkspace::id());
        $stats = [
            Stat::make(__('Recordings'), Number::fileSize($storage['recordings_bytes'], precision: 1))
                ->description(__(':count files · deleted after :days days', ['count' => $storage['recordings_count'], 'days' => $policy->daysFor(null)])),
        ];
        if ($storage['disk_total_bytes'] !== null && $storage['disk_free_bytes'] !== null && $storage['disk_total_bytes'] > 0) {
            $used = 1 - $storage['disk_free_bytes'] / $storage['disk_total_bytes'];
            $stats[] = Stat::make(__('Server disk'), __(':percent used', ['percent' => Number::percentage($used * 100, precision: 0)]))
                ->description(__(':free free of :total', ['free' => Number::fileSize($storage['disk_free_bytes'], precision: 1), 'total' => Number::fileSize($storage['disk_total_bytes'], precision: 1)]))
                ->color($used > 0.9 ? 'danger' : ($used > 0.75 ? 'warning' : 'success'));
        }

        return $stats;
    }
}
