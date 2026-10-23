<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SourceDevice;
use App\Models\CaptureSession;
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
        $start = now()->subDays(13)->startOfDay();
        $rows = CaptureSession::query()
            ->where('user_id', auth()->id())
            ->where('started_at', '>=', $start)
            ->get(['started_at', 'source_device']);

        $labels = [];
        for ($i = 0; $i < 14; $i++) {
            $labels[] = $start->copy()->addDays($i)->format('M j');
        }

        $colors = [SourceDevice::Phone->value => '#10B981', SourceDevice::Desktop->value => '#22D3EE', SourceDevice::Web->value => '#8B5CF6'];
        $datasets = [];
        foreach (SourceDevice::cases() as $device) {
            $series = array_fill(0, 14, 0);
            foreach ($rows as $row) {
                if ($row->source_device === $device) {
                    $index = (int) $start->diffInDays(Carbon::parse($row->started_at)->startOfDay());
                    if ($index >= 0 && $index < 14) {
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
