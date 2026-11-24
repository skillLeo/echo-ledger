<?php

declare(strict_types=1);

namespace App\Filament\Pages\LiveAssist;

use App\Filament\Concerns\LiveAssistOnly;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Services\Assist\AssistPlans;
use App\Services\Assist\AssistRecords;
use App\Services\SettingsRepository;
use App\Services\Workspaces\CurrentWorkspace;
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
use UnitEnum;

/**
 * Live Assist sessions (v1.9 L8): every call with Live Assist and every Practice, newest first, with how long it ran
 * and its report; what is left of this month's Live Assist and Practice time on top. A row opens its report.
 */
class AssistSessions extends Page implements HasTable
{
    use InteractsWithTable;
    use LiveAssistOnly;

    /**
     * Since docs/PLAN_NAV_3_LEVELS.md (Live's panel and Live Assist's tabs), with the three levels only: the Show filter
     * in the address, as a list page keeps it, so Past reports (calls) and Practice are links of their own and Back keeps
     * them. Without them the page's address stays as it was.
     *
     * @return array<string, array{as: string}>
     */
    protected function queryString(): array
    {
        return ConsoleV2::frame() ? ['tableFilters' => ['as' => 'filters']] : [];
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Sparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Live Assist';

    protected static ?string $navigationLabel = 'Sessions';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Live Assist sessions';

    protected static ?string $slug = 'live-assist/sessions';

    public const REPORT_LABELS = ['ready' => 'Ready', 'queued' => 'Being made', 'failed' => 'Could not be made', 'none' => '—'];

    /** In the console's language (the new frame in Urdu); English reads as before. */
    public function getTitle(): string
    {
        return __('Live Assist sessions');
    }

    public static function getNavigationLabel(): string
    {
        return __('Sessions');
    }

    public function getSubheading(): string
    {
        $plans = app(AssistPlans::class);
        $workspace = (int) CurrentWorkspace::id();
        $plan = $plans->for($workspace);
        $live = $plan['live_modes'] === [] ? __('Live Assist in calls is not in your plan')
            : __('Live Assist: :time left', ['time' => self::left($plans->left($workspace, self::personId(), false)['seconds_left'])]);

        return __('This month · :live · Practice: :time left', ['live' => $live, 'time' => self::left($plans->left($workspace, self::personId(), true)['seconds_left'])]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();

        return $table
            ->query(fn (): Builder => AssistSession::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', self::personId())->with('prep'))
            ->defaultSort('started_at', 'desc')
            ->recordUrl(fn (AssistSession $s): string => AssistReportPage::getUrl(['session' => $s->id]))
            ->columns([
                TextColumn::make('started_at')->label(__('Started'))->dateTime('M j, Y · g:i A', $tz)->sortable()
                    ->description(fn (AssistSession $s): string => $s->prep instanceof AssistPrep && $s->prep->title !== null ? $s->prep->title : ''),
                TextColumn::make('practice')->label(__('Kind'))->badge()
                    ->state(fn (AssistSession $s): string => $s->practice ? __('Practice') : __('Call'))
                    ->color(fn (AssistSession $s): string => $s->practice ? 'info' : 'gray'),
                TextColumn::make('mode')->label(__('Mode'))->formatStateUsing(fn (string $state): string => isset(AssistRecords::MODES[$state]) ? __(AssistRecords::MODES[$state]) : $state),
                TextColumn::make('platform')->label(__('On'))->formatStateUsing(fn (string $state): string => ['desktop' => __('Computer'), 'android' => __('Phone'), 'ios' => __('iPhone'), 'web' => __('Web')][$state] ?? $state),
                TextColumn::make('seconds_used')->label(__('Length'))->state(fn (AssistSession $s): string => AssistRecords::duration($s->seconds())),
                TextColumn::make('report_status')->label(__('Report'))->badge()
                    ->state(fn (AssistSession $s): string => $s->ended() ? (isset(self::REPORT_LABELS[$s->report_status]) ? __(self::REPORT_LABELS[$s->report_status]) : '—') : __('On now'))
                    ->color(fn (AssistSession $s): string => ! $s->ended() ? 'warning' : ($s->report_status === 'ready' ? 'success' : 'gray')),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('Show'))->options(['call' => __('Calls'), 'practice' => __('Practice')])->placeholder(__('All'))
                    ->query(fn (Builder $q, array $data): Builder => match ($data['value'] ?? null) {
                        'call' => $q->where('practice', false), 'practice' => $q->where('practice', true), default => $q,
                    }),
            ])
            ->recordActions([
                Action::make('delete')->label(__('Delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (): bool => self::canChange())
                    ->requiresConfirmation()->modalHeading(__('Delete this session?'))
                    ->modalDescription(__('Its words, suggestions and report go now. The time it used still counts this month.'))
                    ->action(function (AssistSession $s): void {
                        AssistRecords::eraseSession($s);
                        Notification::make()->title(__('Session deleted'))->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading(__('No sessions yet'))
            ->emptyStateDescription(__('Start Live Assist in a call, or Practice, from the desktop or phone app. Its report shows here.'));
    }

    private static function left(int $seconds): string
    {
        return $seconds >= 3600 ? __(':hours h :minutes min', ['hours' => intdiv($seconds, 3600), 'minutes' => intdiv($seconds % 3600, 60)])
            : __(':n min', ['n' => intdiv($seconds, 60)]);
    }
}
