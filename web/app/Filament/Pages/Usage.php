<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Usage\UsageReport;
use App\Services\Usage\UsageSummary;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Usage dashboard (B20): one month of this workspace from the usage ledger (minutes by provider, streaming and final,
 * cost, devices, clients), the plan meters, and for the server admin the A5 monthly cap of the whole server.
 */
class Usage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Usage';

    /** @var array{month: string} */
    public array $filters = ['month' => ''];

    public function mount(UsageReport $report): void
    {
        $this->filters['month'] = (string) array_key_first($report->months());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('month')->hiddenLabel()->options(fn (): array => app(UsageReport::class)->months())
                ->selectablePlaceholder(false)->live()->extraAttributes(['style' => 'max-width: 16rem']),
            View::make('filament.pages.usage')->viewData(fn (): array => $this->viewData()),
        ])->statePath('filters');
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $workspaceId = (int) CurrentWorkspace::id();
        $months = app(UsageReport::class)->months();
        $month = array_key_exists($this->filters['month'], $months) ? $this->filters['month'] : (string) array_key_first($months);
        $user = auth()->user();
        $server = $user instanceof User && $user->is_super_admin ? app(UsageSummary::class)->toArray() : null;

        return [
            'report' => app(UsageReport::class)->forMonth($workspaceId, $month),
            'plan' => app(PlanLimits::class)->summary($workspaceId),
            'server' => $server,
            'isCurrentMonth' => $month === array_key_first($months),
            'settingsUrl' => $server !== null ? Settings::getUrl() : null,
        ];
    }
}
