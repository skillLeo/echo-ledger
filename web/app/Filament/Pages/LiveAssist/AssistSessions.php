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

    public function getSubheading(): string
    {
        $plans = app(AssistPlans::class);
        $workspace = (int) CurrentWorkspace::id();
        $plan = $plans->for($workspace);
        $live = $plan['live_modes'] === [] ? 'Live Assist in calls is not in your plan' : 'Live Assist: '.self::left($plans->left($workspace, self::personId(), false)['seconds_left']).' left';

        return 'This month · '.$live.' · Practice: '.self::left($plans->left($workspace, self::personId(), true)['seconds_left']).' left';
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
                TextColumn::make('started_at')->label('Started')->dateTime('M j, Y · g:i A', $tz)->sortable()
                    ->description(fn (AssistSession $s): string => $s->prep instanceof AssistPrep && $s->prep->title !== null ? $s->prep->title : ''),
                TextColumn::make('practice')->label('Kind')->badge()
                    ->state(fn (AssistSession $s): string => $s->practice ? 'Practice' : 'Call')
                    ->color(fn (AssistSession $s): string => $s->practice ? 'info' : 'gray'),
                TextColumn::make('mode')->label('Mode')->formatStateUsing(fn (string $state): string => AssistRecords::MODES[$state] ?? $state),
                TextColumn::make('platform')->label('On')->formatStateUsing(fn (string $state): string => ['desktop' => 'Computer', 'android' => 'Phone', 'ios' => 'iPhone', 'web' => 'Web'][$state] ?? $state),
                TextColumn::make('seconds_used')->label('Length')->state(fn (AssistSession $s): string => AssistRecords::duration($s->seconds())),
                TextColumn::make('report_status')->label('Report')->badge()
                    ->state(fn (AssistSession $s): string => $s->ended() ? (self::REPORT_LABELS[$s->report_status] ?? '—') : 'On now')
                    ->color(fn (AssistSession $s): string => ! $s->ended() ? 'warning' : ($s->report_status === 'ready' ? 'success' : 'gray')),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Show')->options(['call' => 'Calls', 'practice' => 'Practice'])->placeholder('All')
                    ->query(fn (Builder $q, array $data): Builder => match ($data['value'] ?? null) {
                        'call' => $q->where('practice', false), 'practice' => $q->where('practice', true), default => $q,
                    }),
            ])
            ->recordActions([
                Action::make('delete')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (): bool => self::canChange())
                    ->requiresConfirmation()->modalHeading('Delete this session?')
                    ->modalDescription('Its words, suggestions and report go now. The time it used still counts this month.')
                    ->action(function (AssistSession $s): void {
                        AssistRecords::eraseSession($s);
                        Notification::make()->title('Session deleted')->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading('No sessions yet')
            ->emptyStateDescription('Start Live Assist in a call, or Practice, from the desktop or phone app. Its report shows here.');
    }

    private static function left(int $seconds): string
    {
        return $seconds >= 3600 ? intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min' : intdiv($seconds, 60).' min';
    }
}
