<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Pages\Usage;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use App\Services\Usage\UsageBreakdown;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
 * The server admin's usage and costs (since v1.5): minutes, tokens and US dollars per provider (each with its use cases),
 * per workspace and per person in a date range, how much of its plan each workspace has used, the last six months,
 * a CSV, and the price table per provider and unit that the dollars come from. Numbers only, never content.
 */
class ServerUsage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Usage';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Server usage';

    protected static ?string $slug = 'server-usage';

    /** @var array{from: string|null, to: string|null, workspace_id: int|string|null} */
    public array $filters = ['from' => null, 'to' => null, 'workspace_id' => null];

    public static function canAccess(): bool
    {
        return StaffAccess::can('usage.view');
    }

    public function mount(): void
    {
        $now = Carbon::now(app(SettingsRepository::class)->displayTimezone());
        $this->filters['from'] = $now->copy()->startOfMonth()->toDateString();
        $this->filters['to'] = $now->copy()->toDateString();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 3])->schema([
                DatePicker::make('from')->label('From')->native()->required()->live(),
                DatePicker::make('to')->label('To')->native()->required()->live(),
                Select::make('workspace_id')->label('Workspace')->placeholder('Every workspace')->live()->searchable()
                    ->options(fn (): array => Workspace::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all()),
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
            Action::make('prices')->label('Prices')->icon(Heroicon::OutlinedCurrencyDollar)->color('gray')
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

        return [
            'report' => $breakdown->report($from, $to, $this->workspace()),
            'trend' => $breakdown->trend($this->workspace()),
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
