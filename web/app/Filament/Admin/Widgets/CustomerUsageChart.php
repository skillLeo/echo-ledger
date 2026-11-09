<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\User;
use App\Services\Staff\Customers;
use App\Services\Staff\StaffAccess;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

/** A customer's billable minutes per day, the last 30 days (since v1.6, P2.2): on their admin page only. */
class CustomerUsageChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Minutes per day, last 30 days';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '200px';

    #[Locked]
    public int $userId = 0;

    public static function canView(): bool
    {
        return StaffAccess::can('customers.view');
    }

    protected function getData(): array
    {
        abort_unless(StaffAccess::can('customers.view'), 403);
        $user = User::query()->findOrFail($this->userId);
        $daily = app(Customers::class)->daily($user);

        return [
            'datasets' => [['label' => 'Minutes', 'data' => $daily['minutes'], 'borderRadius' => 4]],
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
