<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Usage;

use App\Services\Usage\UsageReport;
use App\Services\Workspaces\CurrentWorkspace;
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
        $daily = app(UsageReport::class)->forMonth((int) CurrentWorkspace::id(), $this->month)['daily'];

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
