<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\DevicePlatform;
use App\Models\MissedRecording;
use App\Services\Staff\StaffAccess;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Missed recordings (since v1.8, P0 0.2, docs/API.md `POST /api/devices/missed-recordings`): calls the apps saw but
 * did not record, counted per device, reason, system and the app version that reported them (contract 1.8.0-c) over the
 * last 7 and 30 days. Only the counts and when the last one was: never a call's length or its app, which stay with
 * their person.
 */
class MissedRecordings extends Page implements HasTable
{
    use InteractsWithTable;

    /** The reasons as the table names them. */
    public const REASONS = [
        'helper_not_armed' => 'Helper not connected',
        'permission' => 'Permission off',
        'detection' => 'Call not detected',
        'mic_busy' => 'Microphone busy',
        'crash' => 'App stopped',
        'plan' => 'Plan did not allow it',
        'unknown' => 'Not known',
    ];

    /** What a row of the table is made of (contract 1.8.0-c: the reporting system and app version too). */
    public const GROUP = ['user_id', 'device_id', 'reason', 'platform', 'app_version'];

    /** The longer of the two periods: older calls are not counted. */
    public const DAYS = 30;

    public const RECENT_DAYS = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneXMark;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::PhoneXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Diagnostics';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Missed recordings';

    protected static ?string $slug = 'missed-recordings';

    public static function canAccess(): bool
    {
        return StaffAccess::can('devices.reports');
    }

    public function getSubheading(): ?string
    {
        return 'Calls the phone and computer apps saw but did not record, per device, reason and app version. An app counts one when it finds a call with no recording; the call itself stays with its person.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::counts())
            // The rows are groups: their order is the query's own (a key sort would name a column outside the groups).
            ->defaultKeySort(false)
            // On a phone each row is a card: the reason and the counts stay in view.
            ->stackedOnMobile()
            ->emptyStateHeading('No missed recordings in the last 30 days')
            ->emptyStateDescription('When a phone or computer finds a call it did not record, it is counted here.')
            ->columns([
                TextColumn::make('device_id')->label('Device')->placeholder('A removed device')
                    ->formatStateUsing(fn (MissedRecording $record): string => $record->device?->readableName() ?? 'A removed device')
                    // What reported these calls: the device row's own version is only the one it signed in with.
                    ->description(fn (MissedRecording $record): string => self::reportedBy($record)),
                TextColumn::make('user.email')->label('Person'),
                TextColumn::make('reason')->label('Reason')
                    ->formatStateUsing(fn (string $state): string => self::REASONS[$state] ?? $state),
                TextColumn::make('last_7_days')->label('Last 7 days')->numeric()
                    ->color(fn (int|string $state): string => (int) $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('last_30_days')->label('Last 30 days')->numeric(),
                TextColumn::make('last_at')->label('Last one')->since()->dateTimeTooltip(),
            ])
            // The filter button's badge shows once a filter is on, not as a 0 (see the theme); its name says the number.
            ->filtersTriggerAction(fn (Action $action, Table $table): Action => $action
                ->extraAttributes(fn (): array => ['data-active-filters' => (string) $table->getActiveFiltersCount()])
                ->label(fn (): string => 'Filters ('.$table->getActiveFiltersCount().' active)'))
            ->filters([
                SelectFilter::make('reason')->label('Reason')->options(self::REASONS),
                SelectFilter::make('platform')->label('System')
                    ->options(collect(DevicePlatform::cases())->mapWithKeys(fn (DevicePlatform $p): array => [$p->value => $p->getLabel()])->all()),
            ]);
    }

    /** "Android · 1.8.0": the system and the app version that reported the row's calls (no version: the system only). */
    public static function reportedBy(MissedRecording $record): string
    {
        $system = DevicePlatform::tryFrom($record->platform)?->getLabel() ?? $record->platform;

        return implode(' · ', array_filter([$system, $record->app_version], fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * One row per device (per person, for a removed device), reason, system and the app version that reported the calls:
     * the calls of the last 7 and of the last 30 days and the newest one's date, the most recent trouble first. `id` is
     * the group's first row, only so the table has a key; no row is ever opened.
     *
     * @return Builder<MissedRecording>
     */
    public static function counts(?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $recent = $now->copy()->subDays(self::RECENT_DAYS);

        return MissedRecording::query()->withoutGlobalScopes()
            ->with(['device', 'user'])
            ->select(self::GROUP)
            ->selectRaw('MIN(id) AS id')
            ->selectRaw('SUM(CASE WHEN call_started_at >= ? THEN 1 ELSE 0 END) AS last_7_days', [$recent])
            ->selectRaw('COUNT(*) AS last_30_days')
            ->selectRaw('MAX(call_started_at) AS last_at')
            ->where('call_started_at', '>=', $now->copy()->subDays(self::DAYS))
            ->groupBy(self::GROUP)
            ->orderByRaw('SUM(CASE WHEN call_started_at >= ? THEN 1 ELSE 0 END) DESC', [$recent])
            ->orderByRaw('COUNT(*) DESC')
            ->orderByRaw('MAX(call_started_at) DESC')
            ->orderBy('user_id')
            ->orderBy('device_id')
            ->orderBy('reason')
            ->orderBy('platform')
            ->orderBy('app_version');
    }
}
