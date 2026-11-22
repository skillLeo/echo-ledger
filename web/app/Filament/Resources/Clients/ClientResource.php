<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients;

use App\Exceptions\ApiError;
use App\Filament\Concerns\FullConsoleOnly;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\KeytermsRelationManager;
use App\Filament\Support\PlanLock;
use App\Filament\Support\SharingActions;
use App\Http\Controllers\Api\OrganiseController;
use App\Models\Client;
use App\Services\Projects\ProjectTree;
use App\Services\SettingsRepository;
use App\Services\TextReplacements;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use BackedEnum;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Projects (v1.5; the API and the database keep calling them clients): folders with subfolders whose recordings, files,
 * notes and keyterms belong together. The list shows the top level; a search looks through every level.
 */
class ClientResource extends Resource
{
    use FullConsoleOnly;

    protected static ?string $model = Client::class;

    protected static ?string $modelLabel = 'project';

    protected static ?string $navigationLabel = 'Projects';

    protected static ?string $breadcrumb = 'Projects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Folder;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    /** In the console's language (W-212). */
    public static function getModelLabel(): string
    {
        return __('project');
    }

    /**
     * Its own plural, not Filament's English "s" on the translated singular ("پروجیکٹs", W-212 visual). Lowercase, as
     * Filament's own plural is: title case makes the same "Projects" page title as before.
     */
    public static function getPluralModelLabel(): string
    {
        return __('projects');
    }

    public static function getNavigationLabel(): string
    {
        return __('Projects');
    }

    public static function getBreadcrumb(): string
    {
        return __('Projects');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->withCount(['keyterms', 'captureSessions', 'children'])
            ->withMax('captureSessions', 'started_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(120)
                    // Unique among the projects of one folder (capitals ignored), like the API.
                    ->rule(fn (Get $get, ?Client $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        $parent = filled($get('parent_id')) ? (string) $get('parent_id') : null;
                        if (is_string($value) && app(ProjectTree::class)->nameTaken((int) CurrentWorkspace::id(), $parent, $value, $record?->id)) {
                            $fail(__(ProjectTree::NAME_TAKEN));
                        }
                    }),
                Select::make('parent_id')->label(__('Inside'))->placeholder(__('Top level (no folder)'))->searchable()
                    ->options(fn (?Client $record): array => SharingActions::folderOptions($record))
                    ->default(fn (): ?string => is_string(request()->query('parent')) ? request()->query('parent') : null)
                    // At most four levels, never inside itself (the same checks as the API).
                    ->rule(fn (?Client $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        try {
                            $tree = app(ProjectTree::class);
                            $tree->assertCanPlace($record, $tree->parentFor((int) CurrentWorkspace::id(), $value));
                        } catch (ApiError $e) {
                            $fail($e->getMessage());
                        }
                    }),
                // Devices parse this as #RRGGBB, so anything else is refused on the server too.
                ColorPicker::make('color')->label(__('Color'))->default('#8B5CF6')
                    ->regex('/^#[0-9A-Fa-f]{6}\z/')
                    ->validationMessages(['regex' => __('Use a colour like #8B5CF6.')]),
                TextInput::make('avatar')->label(__('Avatar'))->maxLength(16)->placeholder(__('AC or an emoji'))
                    ->helperText(__('One emoji or up to two letters, shown on the folder.'))
                    ->dehydrateStateUsing(fn (?string $state): ?string => OrganiseController::avatar($state)),
                Toggle::make('pinned')->label(__('Pin to the top'))->inline(false),
                // Since v1.5: off = the desktop keeps no screen moments of meetings filed here.
                // Since v1.6.2 without screen moments in the plan: the plan's badge, the switch kept as it is, See plans.
                Toggle::make('screens')->label(fn () => PlanLock::label(__('Keep screen moments of its meetings'), 'screen_moments'))->default(true)->inline(false)
                    ->disabled(fn (): bool => ! PlanLock::allows('screen_moments'))
                    ->hintAction(PlanLock::sheet('upgradeScreens', 'screen_moments')->label(__('See plans'))->link()
                        ->visible(fn (): bool => ! PlanLock::allows('screen_moments')))
                    ->helperText(fn (): string => PlanLock::allows('screen_moments')
                        ? __('The desktop app keeps what was on the shared screen at each moment. Off for projects whose screens should not be kept.')
                        : __('Screen moments are not in your plan: the desktop app keeps no screens until you upgrade.')),
                Select::make('language')->label(__('Language'))->options(LanguageChoice::offered())->selectablePlaceholder(false)
                    ->formatStateUsing(fn (?string $state): string => LanguageChoice::normalize($state) ?? '')
                    ->dehydrateStateUsing(fn (?string $state): ?string => LanguageChoice::normalize($state))
                    ->helperText(__('Used for this project’s recordings unless a recording chooses its own. Automatic suits mixed-language calls.')),
                TextInput::make('retention_days')->label(__('Delete this project’s recordings after (days)'))->numeric()->minValue(1)->maxValue(365)->nullable()
                    ->placeholder(fn (): string => __('General setting: :n days', ['n' => app(SettingsRepository::class)->retentionDays()]))
                    ->helperText(__('Empty uses the general setting. 7, 14 or 30 days are common.')),
                Textarea::make('notes')->label(__('Notes'))->rows(3)->maxLength(2000)->columnSpanFull(),
            ]),
            Section::make(__('Corrections'))->description(__('Applied to every new final transcript of this project, before the general ones in Settings.'))
                ->collapsible()->schema([
                    Repeater::make('replacements')->label(__('Replacements'))->hiddenLabel()
                        ->schema([
                            TextInput::make('from')->label(__('When the transcript says'))->required()->maxLength(TextReplacements::MAX_CHARS),
                            TextInput::make('to')->label(__('Write instead'))->maxLength(TextReplacements::MAX_CHARS),
                            Toggle::make('whole_word')->label(__('Whole words only'))->default(true)->inline(false),
                            Toggle::make('match_case')->label(__('Match case'))->inline(false),
                        ])
                        ->columns(4)->defaultItems(0)->maxItems(TextReplacements::MAX_RULES)->addActionLabel(__('Add a correction'))
                        ->dehydrateStateUsing(fn (?array $state): ?array => ($rules = TextReplacements::rules(array_values($state ?? []))) === [] ? null : $rules),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // The top level, like a file browser; a search looks through every level (rows then show their folder).
            ->modifyQueryUsing(fn (Builder $query, ListRecords $livewire): Builder => $query->with('parent:id,name')->orderByDesc('pinned')
                ->when(blank($livewire->getTableSearch()), fn (Builder $q): Builder => $q->whereNull('parent_id')))
            // The phone's client list: initials avatar, name, "2 recordings · Last call Oct 24"; a row opens the workspace.
            ->columns([
                Split::make([
                    ViewColumn::make('name')->label(__('Name'))->view('filament.clients.row')->searchable()->sortable(),
                    TextColumn::make('language')->placeholder(__('Automatic'))->grow(false)->visibleFrom('md')->color('gray')
                        ->formatStateUsing(fn (?string $state): string => LanguageChoice::offered()[(string) $state] ?? (string) $state),
                    IconColumn::make('pinned')->label(__('Pinned'))->boolean()->trueIcon(Heroicon::Star)->falseIcon(Heroicon::OutlinedStar)->trueColor('warning')->falseColor('gray')
                        ->grow(false)->tooltip(fn (Client $record): string => $record->pinned ? __('Unpin') : __('Pin to the top'))
                        ->extraAttributes(fn (Client $record): array => ['class' => $record->pinned ? 'el-pin el-on' : 'el-pin'])
                        ->action(fn (Client $record) => $record->update(['pinned' => ! $record->pinned])),
                ])->extraAttributes(['class' => 'el-split']),
                TextColumn::make('keyterms_count')->label(__('Keyterms'))->hidden(),
                TextColumn::make('capture_sessions_count')->label(__('Recordings'))->hidden(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label(__('Open')),
                    EditAction::make()->label(__('Settings')),
                    SharingActions::renameProject(),
                    SharingActions::moveProject(),
                    SharingActions::deleteProject(),
                ])->label(__('More'))->tooltip(__('More'))->color('gray'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedFolder)
            ->emptyStateHeading(__('No projects yet'))
            ->emptyStateDescription(__('A project groups its recordings, files and notes, and teaches the engines its names and words.'))
            ->emptyStateActions([CreateAction::make()->label(__('Add a project'))]);
    }

    public static function getRelations(): array
    {
        return [ContactsRelationManager::class, KeytermsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
