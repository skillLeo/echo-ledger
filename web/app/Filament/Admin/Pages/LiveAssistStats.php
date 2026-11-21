<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Livewire\Admin\LiveAssistNow;
use App\Models\AssistRelayCheck;
use App\Models\User;
use App\Services\Assist\AssistMonitor;
use App\Services\Assist\AssistStats;
use App\Services\Assist\RelayHealth;
use App\Services\SettingsRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use UnitEnum;

/**
 * Live Assist's numbers for the super admin (v1.9 L8, App\Services\Assist\AssistStats): cost and calls per model and per
 * sold plan (with its tier), cost per assist-hour by mode, the prompt cache's share of the input, how calls ended, and
 * p50 / p95 of each latency stage, overall and per model; since B4 also the relay's state (up or down, uptime, outages,
 * last contact) and the sessions and speed right now. Numbers only: never a word anyone said.
 */
class LiveAssistStats extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Sparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Usage';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Live Assist';

    protected static ?string $slug = 'live-assist';

    /** @var array{preset: string, from: string, to: string} */
    public array $filters = ['preset' => 'this_month', 'from' => '', 'to' => ''];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin && ! $user->isSuspended();
    }

    public function getSubheading(): string
    {
        return 'What Live Assist costs and how fast it answers, from the usage records. No content.';
    }

    public function mount(): void
    {
        $this->applyPreset('this_month');
    }

    public function applyPreset(string $preset): void
    {
        $now = Carbon::now(app(SettingsRepository::class)->displayTimezone());
        [$from, $to] = match ($preset) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_7' => [$now->copy()->subDays(6), $now->copy()],
            'last_30' => [$now->copy()->subDays(29), $now->copy()],
            default => [$now->copy()->startOfMonth(), $now->copy()],
        };
        $this->filters['preset'] = isset(ServerUsage::PRESETS[$preset]) ? $preset : 'this_month';
        $this->filters['from'] = $from->toDateString();
        $this->filters['to'] = $to->toDateString();
    }

    /** "Check the relay now": one check, kept like the scheduled ones, 6 a minute at most. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkRelay')->label('Check the relay now')->icon(Heroicon::OutlinedSignal)
                ->visible(fn (): bool => app(RelayHealth::class)->host() !== null)
                ->action(function (): void {
                    $key = 'check-relay:'.auth()->id();
                    if (RateLimiter::tooManyAttempts($key, 6)) {
                        Notification::make()->title('Wait a moment')->body('Check the relay again in '.RateLimiter::availableIn($key).' seconds.')->warning()->send();

                        return;
                    }
                    RateLimiter::hit($key, 60);
                    $result = app(AssistMonitor::class)->checkNow(AssistRelayCheck::MANUAL);
                    if ($result === null) {
                        return;
                    }
                    $this->dispatch('relay-checked');
                    $result['ok']
                        ? Notification::make()->title('The relay is up')->body("{$result['host']} answered in {$result['ms']} ms.")->success()->send()
                        : Notification::make()->title('The relay is down')->body("{$result['host']}: {$result['error']}")->danger()->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // Since B4: the relay and the sessions right now, above the period's numbers. Its own component refreshes
            // every 30 s by itself; the period's numbers are worked out only when the filters change.
            Livewire::make(LiveAssistNow::class),
            Grid::make(['default' => 1, 'sm' => 3])->schema([
                Select::make('preset')->label('Period')->selectablePlaceholder(false)->live()
                    ->options(array_intersect_key(ServerUsage::PRESETS, array_flip(['this_month', 'last_month', 'last_7', 'last_30', 'custom'])))
                    ->afterStateUpdated(fn (?string $state) => $state !== 'custom' ? $this->applyPreset((string) $state) : null),
                DatePicker::make('from')->label('From')->native()->required()->live()->afterStateUpdated(fn () => $this->filters['preset'] = 'custom'),
                DatePicker::make('to')->label('To')->native()->required()->live()->afterStateUpdated(fn () => $this->filters['preset'] = 'custom'),
            ]),
            View::make('filament.pages.live-assist-stats')->viewData(fn (): array => ['stats' => app(AssistStats::class)->between(...$this->range()), 'stages' => AssistStats::STAGES]),
        ])->statePath('filters');
    }

    /** @return array{0: Carbon, 1: Carbon} the chosen days in the console's time zone, the end exclusive */
    private function range(): array
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $parse = fn (mixed $date, Carbon $fallback): Carbon => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1
            ? Carbon::createFromFormat('Y-m-d', substr($date, 0, 10), $zone)->startOfDay() : $fallback;
        $from = $parse($this->filters['from'], Carbon::now($zone)->startOfMonth());
        $to = $parse($this->filters['to'], Carbon::now($zone))->addDay();

        return [$from, $to->greaterThan($from) ? $to : $from->copy()->addDay()];
    }
}
