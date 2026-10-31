<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SourceDevice;
use App\Models\CaptureSession;
use App\Services\SettingsRepository;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** Sessions per day over the last 14 days, one series per source device. */
class SessionsChart extends ChartWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'Sessions · last 14 days';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        // Days are cut in the console's time zone, the zone the Sessions list shows times in.
        $zone = app(SettingsRepository::class)->displayTimezone();
        $start = now($zone)->subDays(13)->startOfDay();
        $rows = CaptureSession::query()
            ->where('workspace_id', CurrentWorkspace::id())
            ->where('started_at', '>=', $start->copy()->utc())
            ->get(['started_at', 'source_device']);

        $labels = [];
        $dayIndex = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $start->copy()->addDays($i);
            $labels[] = $day->format('M j');
            $dayIndex[$day->format('Y-m-d')] = $i;
        }

        // The phone's palette: green for phones, indigo for computers, slate for web uploads.
        $colors = [SourceDevice::Phone->value => '#30A46C', SourceDevice::Desktop->value => '#3E63DD', SourceDevice::Web->value => '#8B8D98'];
        $datasets = [];
        foreach (SourceDevice::cases() as $device) {
            $series = array_fill(0, 14, 0);
            foreach ($rows as $row) {
                if ($row->source_device === $device) {
                    $index = $dayIndex[Carbon::parse($row->started_at)->setTimezone($zone)->format('Y-m-d')] ?? null;
                    if ($index !== null) {
                        $series[$index]++;
                    }
                }
            }
            $datasets[] = [
                'label' => $device->getLabel(),
                'data' => $series,
                'backgroundColor' => $colors[$device->value],
                'borderColor' => $colors[$device->value],
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
