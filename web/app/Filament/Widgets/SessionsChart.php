<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SourceDevice;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Tokens;
use App\Models\CaptureSession;
use App\Services\SettingsRepository;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/** Sessions per day over the last 14 days, one series per source device. */
class SessionsChart extends ChartWidget
{
    protected static ?int $sort = -1;

    /** Since v1.5 the dashboard of the whole console; members and viewers see their library instead. */
    public static function canView(): bool
    {
        return ConsoleAccess::full();
    }

    protected ?string $heading = 'Recordings · last 14 days';

    /** Since v2.0 in the console's language (English is unchanged). */
    public function getHeading(): string|Htmlable|null
    {
        return __((string) $this->heading);
    }

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
            ->where('user_id', CurrentWorkspace::userId())
            ->where('started_at', '>=', $start->copy()->utc())
            ->get(['started_at', 'source_device']);

        $labels = [];
        $dayIndex = [];
        for ($i = 0; $i < 14; $i++) {
            $day = $start->copy()->addDays($i);
            $labels[] = ConsoleV2::shell() ? $day->copy()->locale(app()->getLocale())->translatedFormat('j M') : $day->format('M j');
            $dayIndex[$day->format('Y-m-d')] = $i;
        }

        // The phone's palette: green for phones, indigo for computers, slate for web uploads.
        $colors = [SourceDevice::Phone->value => '#30A46C', SourceDevice::Desktop->value => '#3E63DD', SourceDevice::Web->value => '#8B8D98'];
        // Since v2.0 (ui_v2.shell) the design values' roles, read from tokens.css: no indigo.
        if (ConsoleV2::shell()) {
            $colors = [SourceDevice::Phone->value => Tokens::colour('success-default'), SourceDevice::Desktop->value => Tokens::colour('accent-default'),
                SourceDevice::Web->value => Tokens::colour('text-secondary')];
        }
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
                'label' => __($device->getLabel()),
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
