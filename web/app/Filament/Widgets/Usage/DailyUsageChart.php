<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Usage;

use App\Models\User;
use App\Services\Usage\UsageReport;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\Workspaces;
use Filament\Widgets\ChartWidget;

/** Billable minutes per day of one month, on the Usage page only (never on the dashboard). */
class DailyUsageChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Billable minutes per day';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '220px';

    /** `Y-m`; the workspace always comes from the signed-in user, never from the browser. */
    public string $month = '';

    protected function getData(): array
    {
        // A member's chart is their own minutes; owners and admins see the workspace's (since v1.5).
        $user = auth()->user();
        $workspace = (int) CurrentWorkspace::id();
        $own = $user instanceof User && app(Workspaces::class)->roleOf($user, $workspace)?->manages() !== true;
        $daily = app(UsageReport::class)->forMonth($workspace, $this->month, $own ? $user->id : null)['daily'];

        return [
            'datasets' => [[
                'label' => 'Minutes',
                'data' => $daily['minutes'],
                'borderRadius' => 4,
            ]],
            'labels' => $daily['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
