<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\ShareCheckResult;
use App\Services\Staff\StaffAccess;
use BackedEnum;
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
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Screen-share checks (since v1.8.1, docs/API.md "Screen-share privacy"): the Share Check results people chose to send,
 * only as a matrix: one row per system, OS build, app version and method (and meeting app, its version and share mode
 * for a guided test) with pass, fail and error counts and the last date. Never who sent them, and never contents: the
 * results carry none.
 */
class ShareChecks extends Page implements HasTable
{
    use InteractsWithTable;

    /** The columns a row of the matrix is made of. */
    public const GROUP = ['platform', 'os_build', 'app_version', 'method', 'meeting_app', 'meeting_app_version', 'share_mode'];

    public const PLATFORMS = ['windows' => 'Windows', 'macos' => 'macOS', 'android' => 'Android'];

    public const METHODS = [
        'wgc' => 'Windows Graphics Capture', 'dxgi' => 'Desktop Duplication', 'sck' => 'ScreenCaptureKit',
        'readback' => 'Flag read-back', 'guided' => 'Guided test', 'recording_callback' => 'Screen recording signal',
    ];

    public const MEETING_APPS = ['zoom' => 'Zoom', 'meet' => 'Google Meet', 'teams' => 'Microsoft Teams', 'slack' => 'Slack', 'whatsapp' => 'WhatsApp', 'other' => 'Another app'];

    public const SHARE_MODES = ['screen' => 'whole screen', 'window' => 'one window', 'tab' => 'a tab'];

    public const RESULTS = ['pass' => 'Hidden (pass)', 'fail' => 'Visible (fail)', 'error' => 'Could not check (error)'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Diagnostics';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Screen-share checks';

    protected static ?string $slug = 'share-checks';

    public static function canAccess(): bool
    {
        return StaffAccess::can('devices.reports');
    }

    public function getSubheading(): ?string
    {
        return 'Whether EchoLedger stayed out of screen shares, counted per system, app version and way of checking. Results arrive only from people who allow diagnostics, and never say who sent them.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::matrix())
            ->emptyStateHeading('No screen-share checks yet')
            ->emptyStateDescription('Settings → Screen-share privacy → Check screen-share privacy, with Send diagnostics on.')
            ->columns([
                TextColumn::make('platform')->label('System')
                    ->formatStateUsing(fn (ShareCheckResult $record): string => (self::PLATFORMS[$record->platform] ?? $record->platform).' '.$record->os_build),
                TextColumn::make('app_version')->label('App version'),
                TextColumn::make('method')->label('Check')
                    ->formatStateUsing(fn (string $state): string => self::METHODS[$state] ?? $state),
                TextColumn::make('meeting_app')->label('Meeting app')->placeholder('None')
                    ->formatStateUsing(fn (ShareCheckResult $record): string => self::meetingApp($record)),
                TextColumn::make('passed')->label('Pass')->numeric()->color('success'),
                TextColumn::make('failed')->label('Fail')->numeric()->color(fn (int|string $state): string => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('errored')->label('Error')->numeric()->color(fn (int|string $state): string => (int) $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('last_at')->label('Last check')
                    ->formatStateUsing(fn (mixed $state): string => Carbon::parse((string) $state)->toFormattedDateString()),
            ])
            // The filter button's badge shows once a filter is on, not as a 0 (see the theme); its name says the number.
            ->filtersTriggerAction(fn (Action $action, Table $table): Action => $action
                ->extraAttributes(fn (): array => ['data-active-filters' => (string) $table->getActiveFiltersCount()])
                ->label(fn (): string => 'Filters ('.$table->getActiveFiltersCount().' active)'))
            ->filters([
                SelectFilter::make('platform')->label('System')->options(self::PLATFORMS),
                // The groups with at least one such result, their other counts kept: a HAVING, so on the base query
                // (the filters' own query is a nested WHERE).
                SelectFilter::make('result')->label('Result')->options(self::RESULTS)
                    ->query(fn (Builder $query): Builder => $query)
                    ->baseQuery(fn (Builder $query, array $data): Builder => in_array($data['value'] ?? null, ShareCheckResult::RESULTS, true)
                        ? $query->havingRaw('SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) > 0', [$data['value']])
                        : $query),
            ]);
    }

    /**
     * The matrix: one row per group with its counts, newest first. `id` is the group's first result, only so the table
     * has a key; no row is ever opened.
     *
     * @return Builder<ShareCheckResult>
     */
    public static function matrix(): Builder
    {
        return ShareCheckResult::query()
            ->select(self::GROUP)
            ->selectRaw('MIN(id) AS id')
            ->selectRaw("SUM(CASE WHEN result = 'pass' THEN 1 ELSE 0 END) AS passed")
            ->selectRaw("SUM(CASE WHEN result = 'fail' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN result = 'error' THEN 1 ELSE 0 END) AS errored")
            ->selectRaw('MAX(created_at) AS last_at')
            ->groupBy(self::GROUP)
            ->orderByRaw('MAX(created_at) DESC')
            ->orderBy('platform')
            ->orderBy('os_build');
    }

    /** "Zoom 6.4.1, whole screen", or "" for a check without a meeting app. */
    public static function meetingApp(ShareCheckResult $record): string
    {
        if ($record->meeting_app === null) {
            return '';
        }
        $name = trim((self::MEETING_APPS[$record->meeting_app] ?? $record->meeting_app).' '.$record->meeting_app_version);

        return $record->share_mode === null ? $name : $name.', '.(self::SHARE_MODES[$record->share_mode] ?? $record->share_mode);
    }
}
