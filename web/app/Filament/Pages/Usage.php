<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Usage\UsageBreakdown;
use App\Services\Usage\UsageReport;
use App\Services\Usage\UsageSummary;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Usage dashboard (B20): one month from the usage ledger (minutes by provider, streaming and final, cost, devices,
 * clients), the plan meters, and for the server admin the A5 monthly cap of the whole server. Since v1.5 owners and
 * admins see the workspace's numbers per person (never content), a member only their own, a viewer none of it.
 */
class Usage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Usage';

    /** @var array{month: string} */
    public array $filters = ['month' => ''];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return $user->is_super_admin || app(Workspaces::class)->roleOf($user, (int) CurrentWorkspace::id())?->records() === true;
    }

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function (): StreamedResponse {
                    [$from, $to] = $this->range();
                    $rows = app(UsageBreakdown::class)->csv($from, $to, (int) CurrentWorkspace::id(), $this->ownOnly());

                    return self::csvResponse($rows, 'usage-'.$from->format('Y-m').'.csv');
                }),
            Action::make('serverUsage')->label('Whole server')->icon(Heroicon::OutlinedServerStack)->color('gray')
                ->visible(fn (): bool => ServerUsage::canAccess())->url(fn (): string => ServerUsage::getUrl()),
        ];
    }

    /** @param  list<list<string|int|float>>  $rows */
    public static function csvResponse(array $rows, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The person whose numbers alone this viewer sees, or null for the whole workspace. */
    private function ownOnly(): ?int
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return 0;
        }

        return app(Workspaces::class)->roleOf($user, (int) CurrentWorkspace::id())?->manages() === true ? null : $user->id;
    }

    /** @return array{0: Carbon, 1: Carbon} the chosen month's bounds in the console's time zone */
    private function range(): array
    {
        $months = app(UsageReport::class)->months();
        $month = array_key_exists($this->filters['month'], $months) ? $this->filters['month'] : (string) array_key_first($months);
        $from = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', app(SettingsRepository::class)->displayTimezone());

        return [$from, $from->copy()->addMonthNoOverflow()];
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $workspaceId = (int) CurrentWorkspace::id();
        $months = app(UsageReport::class)->months();
        $month = array_key_exists($this->filters['month'], $months) ? $this->filters['month'] : (string) array_key_first($months);
        $user = auth()->user();
        $server = $user instanceof User && $user->is_super_admin ? app(UsageSummary::class)->toArray() : null;
        $own = $this->ownOnly();
        [$from, $to] = $this->range();
        $breakdown = app(UsageBreakdown::class);

        return [
            'report' => app(UsageReport::class)->forMonth($workspaceId, $month, $own),
            'plan' => app(PlanLimits::class)->summary($workspaceId),
            'server' => $server,
            'isCurrentMonth' => $month === array_key_first($months),
            'settingsUrl' => $server !== null ? Settings::getUrl() : null,
            'ownOnly' => $own !== null,
            // Owners and admins: the workspace per person. Everyone with this page: their trend.
            'people' => $own === null ? $breakdown->report($from, $to, $workspaceId)['people'] : null,
            'trend' => $breakdown->trend($workspaceId, $own),
        ];
    }
}
