<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Pages\Usage;
use App\Filament\Support\Notice as Notification;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use App\Services\Usage\CostAlerts;
use App\Services\Usage\UsageBreakdown;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Usage and costs (since v1.5; the admin panel's since v1.6, P2.6): minutes, tokens and US dollars per provider (each
 * with its use cases), per plan, per workspace and per person in a period (presets or dates, compared with the period
 * before), the top customers, a chart per day, the last six months, a CSV, the price table the dollars come from, and
 * the monthly budget alert. Numbers only, never content.
 */
class ServerUsage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Usage';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Server usage';

    protected static ?string $slug = 'server-usage';

    /** @var array{preset: string, from: string|null, to: string|null, workspace_id: int|string|null, compare: bool} */
    public array $filters = ['preset' => 'this_month', 'from' => null, 'to' => null, 'workspace_id' => null, 'compare' => true];

    /** The date presets (since v1.6, P2.6). */
    public const PRESETS = ['this_month' => 'This month', 'last_month' => 'Last month', 'last_7' => 'Last 7 days', 'last_30' => 'Last 30 days',
        'this_year' => 'This year', 'custom' => 'Choose dates'];

    public static function canAccess(): bool
    {
        return StaffAccess::can('usage.view');
    }

    public function mount(): void
    {
        $this->applyPreset('this_month');
    }

    /** Fills the dates of [$preset] (in the console's time zone, "to" included). */
    public function applyPreset(string $preset): void
    {
        $now = Carbon::now(app(SettingsRepository::class)->displayTimezone());
        [$from, $to] = match ($preset) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_7' => [$now->copy()->subDays(6), $now->copy()],
            'last_30' => [$now->copy()->subDays(29), $now->copy()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()],
            default => [$now->copy()->startOfMonth(), $now->copy()],
        };
        $this->filters['preset'] = isset(self::PRESETS[$preset]) ? $preset : 'this_month';
        $this->filters['from'] = $from->toDateString();
        $this->filters['to'] = $to->toDateString();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 5])->schema([
                Select::make('preset')->label('Period')->options(self::PRESETS)->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(fn (?string $state) => $state !== 'custom' ? $this->applyPreset((string) $state) : null),
                DatePicker::make('from')->label('From')->native()->required()->live()->afterStateUpdated(fn () => $this->filters['preset'] = 'custom'),
                DatePicker::make('to')->label('To')->native()->required()->live()->afterStateUpdated(fn () => $this->filters['preset'] = 'custom'),
                Select::make('workspace_id')->label('Workspace')->placeholder('Every workspace')->live()->searchable()
                    ->options(fn (): array => Workspace::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()),
                Toggle::make('compare')->label('Compare with the period before')->live()->inline(false),
            ]),
            View::make('filament.pages.server-usage')->viewData(fn (): array => $this->viewData()),
        ])->statePath('filters');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function (): StreamedResponse {
                    [$from, $to] = $this->range();

                    return Usage::csvResponse(app(UsageBreakdown::class)->csv($from, $to, $this->workspace()), 'server-usage-'.$from->format('Y-m-d').'-'.$to->copy()->subDay()->format('Y-m-d').'.csv');
                }),
            Action::make('budgetAlert')->label('Budget alert')->icon(Heroicon::OutlinedBellAlert)->color('gray')
                ->authorize(fn (): bool => StaffAccess::can('settings.manage'))
                ->modalDescription('Staff who see usage get a notice and an email when this month\'s estimated cost passes 80% of it, and again at 100%. Nothing stops: it is an alert, not a limit. Empty: no alert.')
                ->fillForm(fn (): array => ['usd' => app(CostAlerts::class)->budget()])
                ->schema([TextInput::make('usd')->label('Monthly budget (US$)')->numeric()->minValue(1)->maxValue(10000000)])
                ->action(function (array $data): void {
                    $usd = filled($data['usd'] ?? null) ? round((float) $data['usd'], 2) : null;
                    app(SettingsRepository::class)->set(CostAlerts::SETTING, $usd);
                    app(AuditLog::class)->record('settings_changed', (int) auth()->id(), null, $usd === null ? 'Budget alert turned off' : 'Budget alert set to $'.number_format($usd, 2).' a month');
                    Notification::make()->title($usd === null ? 'No budget alert' : 'Alert at $'.number_format($usd, 2).' a month')->success()->send();
                }),
            Action::make('prices')->label('Prices')
                ->authorize(fn (): bool => StaffAccess::can('settings.manage'))->icon(Heroicon::OutlinedCurrencyDollar)->color('gray')
                ->modalDescription('What the dollars on this page come from, in US$ per unit. The rates in Settings still price each new row of the ledger and the monthly limits.')
                ->fillForm(fn (): array => $this->priceState())
                ->schema(fn (): array => $this->priceFields())
                ->action(function (array $data): void {
                    app(UsageBreakdown::class)->savePrices(self::pricesFrom($data));
                    app(AuditLog::class)->record('prices_changed', (int) auth()->id(), null, 'The usage price table changed');
                    Notification::make()->title('Prices saved')->success()->send();
                }),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} the chosen days in the console's time zone, the end exclusive */
    private function range(): array
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $parse = fn (mixed $date, Carbon $fallback): Carbon => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1
            ? Carbon::createFromFormat('Y-m-d', substr($date, 0, 10), $zone)->startOfDay() : $fallback;
        $from = $parse($this->filters['from'] ?? null, Carbon::now($zone)->startOfMonth());
        $to = $parse($this->filters['to'] ?? null, Carbon::now($zone)->startOfDay());

        return $to->lt($from) ? [$from, $from->copy()->addDay()] : [$from, $to->copy()->addDay()];
    }

    private function workspace(): ?int
    {
        $id = $this->filters['workspace_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        [$from, $to] = $this->range();
        $breakdown = app(UsageBreakdown::class);
        $report = $breakdown->report($from, $to, $this->workspace());
        // The period just before, as long as this one, for the comparison.
        $previous = (bool) $this->filters['compare']
            ? $breakdown->report($from->copy()->subDays((int) $from->diffInDays($to)), $from->copy(), $this->workspace())['totals'] : null;
        $plans = [];
        foreach ($report['workspaces'] as $workspace) {
            $plans[$workspace['plan']] ??= ['plan' => $workspace['plan'], 'workspaces' => 0, 'minutes' => 0.0, 'cost_usd' => 0.0];
            $plans[$workspace['plan']]['workspaces']++;
            $plans[$workspace['plan']]['minutes'] += $workspace['minutes'];
            $plans[$workspace['plan']]['cost_usd'] += $workspace['cost_usd'];
        }
        usort($plans, fn (array $a, array $b): int => $b['cost_usd'] <=> $a['cost_usd']);
        $top = $report['people'];
        usort($top, fn (array $a, array $b): int => $b['cost_usd'] <=> $a['cost_usd'] ?: $b['minutes'] <=> $a['minutes']);

        return [
            'report' => $report,
            'previous' => $previous,
            'plans' => $plans,
            'top' => array_slice($top, 0, 10),
            'customerUrl' => fn (int $id): ?string => StaffAccess::can('customers.view') ? ViewCustomer::getUrl(['record' => $id], panel: 'admin') : null,
            'daily' => $breakdown->daily($from, $to, $this->workspace()),
            'trend' => $breakdown->trend($this->workspace()),
            'budget' => app(CostAlerts::class)->status(),
            'from' => $from->format('M j, Y'),
            'to' => $to->copy()->subDay()->format('M j, Y'),
        ];
    }

    /** @return list<Section> one small section per provider */
    private function priceFields(): array
    {
        $sections = [];
        foreach (UsageBreakdown::PROVIDERS as $key => $label) {
            $fields = [];
            foreach (UsageBreakdown::UNITS as $unit => $unitLabel) {
                $fields[] = TextInput::make("{$key}__{$unit}")->label($unitLabel)->numeric()->minValue(0)->maxValue(100000)->required();
            }
            $sections[] = Section::make($label)->schema([Grid::make(['default' => 2])->schema($fields)])->compact();
        }

        return $sections;
    }

    /** @return array<string, float> */
    private function priceState(): array
    {
        $state = [];
        foreach (app(UsageBreakdown::class)->prices() as $provider => $units) {
            foreach ($units as $unit => $price) {
                $state["{$provider}__{$unit}"] = $price;
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, float>>
     */
    private static function pricesFrom(array $data): array
    {
        $prices = [];
        foreach (array_keys(UsageBreakdown::PROVIDERS) as $provider) {
            foreach (array_keys(UsageBreakdown::UNITS) as $unit) {
                $prices[$provider][$unit] = round((float) ($data["{$provider}__{$unit}"] ?? 0), 6);
            }
        }

        return $prices;
    }
}
