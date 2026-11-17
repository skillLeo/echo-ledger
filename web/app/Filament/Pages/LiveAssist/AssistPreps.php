<?php

declare(strict_types=1);

namespace App\Filament\Pages\LiveAssist;

use App\Filament\Concerns\LiveAssistOnly;
use App\Filament\Support\Notice as Notification;
use App\Models\AssistPrep;
use App\Models\Client;
use App\Models\ClientFile;
use App\Services\Assist\AssistRecords;
use App\Services\Assist\PromptPack;
use App\Services\SettingsRepository;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
 * Live Assist preps (v1.9 L8, docs/API_ASSIST.md "Preps"): make one for a meeting (its type, who, the goals, what to
 * discuss, a project and its documents, the mode, the hint level and language help), schedule it, change or cancel
 * it. A prep is started from the desktop or phone app ("Start now", or armed before its time); the console holds the
 * same preps as the apps.
 */
class AssistPreps extends Page implements HasTable
{
    use InteractsWithTable;
    use LiveAssistOnly;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Live Assist';

    protected static ?string $navigationLabel = 'Preps';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Live Assist preps';

    protected static ?string $slug = 'live-assist/preps';

    public const STATUS_LABELS = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'armed' => 'Waiting for the meeting', 'done' => 'Started', 'cancelled' => 'Cancelled'];

    public function getSubheading(): string
    {
        return 'Get ready for a meeting here, then start it from the desktop or phone app.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New prep')->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => self::canChange())
                ->modalHeading('New prep')->modalWidth('3xl')
                ->fillForm(fn (): array => ['meeting_type' => 'general', 'hint_level' => self::defaultHint('general'), 'mode' => 'balanced', 'language_help' => 'off'])
                ->schema(fn (): array => self::form())
                ->modalSubmitActionLabel('Save prep')
                ->action(function (array $data): void {
                    $prep = new AssistPrep(['workspace_id' => (int) CurrentWorkspace::id(), 'user_id' => self::personId()]);
                    self::save($prep, $data);
                    Notification::make()->title('Prep saved')->body('Start it from the desktop or phone app.')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();

        return $table
            ->query(fn (): Builder => AssistPrep::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', self::personId())->with(['project', 'documents']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Meeting')->weight('semibold')->placeholder('Untitled')
                    ->description(fn (AssistPrep $p): string => AssistRecords::MEETING_TYPES[$p->meeting_type].($p->counterpart !== null ? ' · with '.$p->counterpart : '')),
                TextColumn::make('project.name')->label('Project')->placeholder('—'),
                TextColumn::make('mode')->label('Mode')->formatStateUsing(fn (string $state): string => AssistRecords::MODES[$state] ?? $state)
                    ->description(fn (AssistPrep $p): string => AssistRecords::HINT_LEVELS[$p->hint_level].' hints'),
                TextColumn::make('documents_count')->label('Documents')->state(fn (AssistPrep $p): int => $p->documents->count())->badge()->color('gray'),
                TextColumn::make('scheduled_at')->label('When')->dateTime('M j, Y · g:i A', $tz)->placeholder('Not scheduled')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (AssistPrep $p): string => self::STATUS_LABELS[$p->currentStatus()] ?? $p->currentStatus())
                    ->color(fn (AssistPrep $p): string => match ($p->currentStatus()) {
                        'scheduled', 'armed' => 'info', 'done' => 'success', 'cancelled' => 'gray', default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('shown')->label('Show')->options(['open' => 'To come', 'all' => 'All'])->default('open')->selectablePlaceholder(false)
                    ->query(fn (Builder $q, array $data): Builder => ($data['value'] ?? 'open') === 'open' ? $q->whereIn('status', ['draft', 'scheduled', 'armed']) : $q),
            ])
            ->recordActions([
                Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->visible(fn (AssistPrep $p): bool => self::canChange() && ! in_array($p->currentStatus(), ['done', 'cancelled'], true))
                    ->modalHeading('Edit prep')->modalWidth('3xl')
                    ->fillForm(fn (AssistPrep $p): array => [
                        ...$p->only(['meeting_type', 'counterpart', 'company', 'goals', 'done', 'not_done', 'to_discuss', 'instructions', 'hint_level', 'mode', 'language_help']),
                        'title' => $p->title, 'client_id' => $p->client_id, 'file_ids' => $p->documents->pluck('id')->all(),
                        'scheduled_at' => $p->scheduled_at?->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('Y-m-d H:i:s'),
                    ])
                    ->schema(fn (): array => self::form())
                    ->modalSubmitActionLabel('Save prep')
                    ->action(function (AssistPrep $p, array $data): void {
                        self::save($p, $data);
                        Notification::make()->title('Prep saved')->success()->send();
                    }),
                Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXCircle)->color('gray')
                    ->visible(fn (AssistPrep $p): bool => self::canChange() && in_array($p->currentStatus(), ['scheduled', 'armed'], true))
                    ->requiresConfirmation()->modalHeading('Cancel this prep?')->modalDescription('It is kept, and the apps no longer wait for its meeting.')
                    ->modalSubmitActionLabel('Cancel prep')->modalCancelActionLabel('Keep it')
                    ->action(fn (AssistPrep $p) => $p->forceFill(['status' => 'cancelled', 'armed_until' => null])->save()),
                Action::make('delete')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (): bool => self::canChange())
                    ->requiresConfirmation()->modalHeading('Delete this prep?')
                    ->modalDescription('Its notes and documents go now. Sessions started from it keep their reports.')
                    ->action(function (AssistPrep $p): void {
                        AssistRecords::erasePrep($p);
                        Notification::make()->title('Prep deleted')->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading('No preps yet')
            ->emptyStateDescription('A prep tells Live Assist about a meeting: who it is with, your goals and what to discuss.');
    }

    /** @return list<Component|Field> */
    private static function form(): array
    {
        $text = fn (string $name, string $label, string $placeholder = ''): Textarea => Textarea::make($name)->label($label)->rows(3)->maxLength(AssistPrep::MAX_TEXT)->placeholder($placeholder);

        return [
            Grid::make(2)->schema([
                Select::make('meeting_type')->label('Meeting type')->options(AssistRecords::MEETING_TYPES)->required()->selectablePlaceholder(false)->live()
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('hint_level', self::defaultHint((string) $state))),
                TextInput::make('title')->label('Title')->placeholder('Interview at Acme')->maxLength(200),
                TextInput::make('counterpart')->label('With')->placeholder('Sara Khan')->maxLength(200),
                TextInput::make('company')->label('Company')->maxLength(200),
                Select::make('client_id')->label('Project')->placeholder('None')->searchable()->live()
                    ->options(fn (): array => self::projects()->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('file_ids', [])),
                Select::make('file_ids')->label('Documents')->multiple()->maxItems(20)
                    ->helperText('Files of the project that Live Assist reads, such as a job description or a brief.')
                    ->options(fn (Get $get): array => filled($get('client_id')) ? self::files((string) $get('client_id'))->pluck('name', 'id')->all() : [])
                    ->disabled(fn (Get $get): bool => blank($get('client_id'))),
            ]),
            Section::make('What to know')->compact()->schema([
                $text('goals', 'My goals', 'Get the offer; agree the start date'),
                Grid::make(2)->schema([$text('done', 'Done so far'), $text('not_done', 'Not done yet')]),
                $text('to_discuss', 'To discuss'),
                $text('instructions', 'Instructions for Live Assist', 'Keep answers short; mention my Laravel work'),
            ]),
            Grid::make(4)->schema([
                Select::make('mode')->label('Mode')->options(AssistRecords::MODES)->required()->selectablePlaceholder(false)
                    ->helperText('Your plan decides which modes start.'),
                Select::make('hint_level')->label('Hints')->options(AssistRecords::HINT_LEVELS)->required()->selectablePlaceholder(false),
                Select::make('language_help')->label('Language help')->options(AssistRecords::LANGUAGE_HELP)->required()->selectablePlaceholder(false),
                DateTimePicker::make('scheduled_at')->label('When')->seconds(false)->timezone(app(SettingsRepository::class)->displayTimezone())
                    ->helperText('Leave empty to start it whenever you like.'),
            ]),
        ];
    }

    /**
     * Saves [$prep] from the form: only the person's own project and its files, whatever the browser sent.
     *
     * @param  array<string, mixed>  $data
     */
    private static function save(AssistPrep $prep, array $data): void
    {
        foreach (['meeting_type' => AssistRecords::MEETING_TYPES, 'mode' => AssistRecords::MODES, 'hint_level' => AssistRecords::HINT_LEVELS, 'language_help' => AssistRecords::LANGUAGE_HELP] as $field => $allowed) {
            if (array_key_exists((string) ($data[$field] ?? ''), $allowed)) {
                $prep->setAttribute($field, $data[$field]);
            }
        }
        foreach (AssistPrep::TEXT_FIELDS as $field) {
            $value = is_string($data[$field] ?? null) ? trim(mb_substr($data[$field], 0, AssistPrep::MAX_TEXT)) : '';
            $prep->setAttribute($field, $value === '' ? null : $value);
        }
        $project = filled($data['client_id'] ?? null) ? self::projects()->find($data['client_id']) : null;
        $prep->client_id = $project?->id;
        $prep->scheduled_at = filled($data['scheduled_at'] ?? null) ? Carbon::parse((string) $data['scheduled_at'], 'UTC')->utc() : null;
        // A draft or a scheduled prep follows its time; an armed one stays armed until it starts or is cancelled.
        if (in_array($prep->status, [null, '', 'draft', 'scheduled'], true)) {
            $prep->status = $prep->scheduled_at !== null ? 'scheduled' : 'draft';
        }
        $prep->save();

        $files = $project instanceof Client ? self::files($project->id)->whereIn('id', (array) ($data['file_ids'] ?? []))->get() : collect();
        // About four characters a token, as the device API counts a document.
        $prep->documents()->sync($files->mapWithKeys(fn (ClientFile $f): array => [$f->id => ['tokens' => (int) ceil(mb_strlen((string) $f->text) / 4)]])->all());
    }

    /** @return Builder<Client> the person's own projects in this workspace (projects are private, v1.5) */
    private static function projects(): Builder
    {
        return Client::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', self::personId())->orderBy('name');
    }

    /** @return Builder<ClientFile> the ready files of one of the person's own projects */
    private static function files(string $projectId): Builder
    {
        return ClientFile::query()->where('workspace_id', CurrentWorkspace::id())->where('status', 'ready')
            ->whereIn('client_id', self::projects()->whereKey($projectId)->select('id'))->orderBy('name');
    }

    private static function defaultHint(string $meetingType): string
    {
        $level = PromptPack::shipped()->defaultHintLevel($meetingType);

        return array_key_exists($level, AssistRecords::HINT_LEVELS) ? $level : 'full';
    }
}
