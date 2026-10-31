<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\KeytermsRelationManager;
use App\Http\Controllers\Api\OrganiseController;
use App\Models\Client;
use App\Services\SettingsRepository;
use App\Services\TextReplacements;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/** Client folders whose keyterms boost accuracy for live captions and final transcripts. */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('workspace_id', CurrentWorkspace::id())->withCount(['keyterms', 'captureSessions'])
            ->withMax('captureSessions', 'started_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('workspace_id', CurrentWorkspace::id())),
                // Devices parse this as #RRGGBB, so anything else is refused on the server too.
                ColorPicker::make('color')->default('#8B5CF6')
                    ->regex('/^#[0-9A-Fa-f]{6}\z/')
                    ->validationMessages(['regex' => 'Use a colour like #8B5CF6.']),
                TextInput::make('avatar')->label('Avatar')->maxLength(16)->placeholder('AC or an emoji')
                    ->helperText('One emoji or up to two letters, shown on the folder.')
                    ->dehydrateStateUsing(fn (?string $state): ?string => OrganiseController::avatar($state)),
                Toggle::make('pinned')->label('Pin to the top')->inline(false),
                Select::make('language')->label('Language')->options(LanguageChoice::OFFERED)->selectablePlaceholder(false)
                    ->formatStateUsing(fn (?string $state): string => LanguageChoice::normalize($state) ?? '')
                    ->dehydrateStateUsing(fn (?string $state): ?string => LanguageChoice::normalize($state))
                    ->helperText('Used for this client’s recordings unless a recording chooses its own. Automatic suits mixed-language calls.'),
                TextInput::make('retention_days')->label('Delete this client’s recordings after (days)')->numeric()->minValue(1)->maxValue(365)->nullable()
                    ->placeholder(fn (): string => 'General setting: '.app(SettingsRepository::class)->retentionDays().' days')
                    ->helperText('Empty uses the general setting. 7, 14 or 30 days are common.'),
                Textarea::make('notes')->rows(3)->maxLength(2000)->columnSpanFull(),
            ]),
            Section::make('Corrections')->description('Applied to every new final transcript of this client, before the general ones in Settings.')
                ->collapsible()->schema([
                    Repeater::make('replacements')->hiddenLabel()
                        ->schema([
                            TextInput::make('from')->label('When the transcript says')->required()->maxLength(TextReplacements::MAX_CHARS),
                            TextInput::make('to')->label('Write instead')->maxLength(TextReplacements::MAX_CHARS),
                            Toggle::make('whole_word')->label('Whole words only')->default(true)->inline(false),
                            Toggle::make('match_case')->label('Match case')->inline(false),
                        ])
                        ->columns(4)->defaultItems(0)->maxItems(TextReplacements::MAX_RULES)->addActionLabel('Add a correction')
                        ->dehydrateStateUsing(fn (?array $state): ?array => ($rules = TextReplacements::rules(array_values($state ?? []))) === [] ? null : $rules),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderByDesc('pinned'))
            // The phone's client list: initials avatar, name, "2 recordings · Last call Oct 24"; a row opens the workspace.
            ->columns([
                Split::make([
                    ViewColumn::make('name')->label('Name')->view('filament.clients.row')->searchable()->sortable(),
                    TextColumn::make('language')->placeholder('Automatic')->grow(false)->visibleFrom('md')->color('gray')
                        ->formatStateUsing(fn (?string $state): string => LanguageChoice::OFFERED[(string) $state] ?? (string) $state),
                    IconColumn::make('pinned')->label('Pinned')->boolean()->trueIcon(Heroicon::Star)->falseIcon(Heroicon::OutlinedStar)->trueColor('warning')->falseColor('gray')
                        ->grow(false)->tooltip(fn (Client $record): string => $record->pinned ? 'Unpin' : 'Pin to the top')
                        ->extraAttributes(fn (Client $record): array => ['class' => $record->pinned ? 'el-pin el-on' : 'el-pin'])
                        ->action(fn (Client $record) => $record->update(['pinned' => ! $record->pinned])),
                ])->extraAttributes(['class' => 'el-split']),
                TextColumn::make('keyterms_count')->label('Keyterms')->hidden(),
                TextColumn::make('capture_sessions_count')->label('Recordings')->hidden(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('Open'),
                    EditAction::make()->label('Settings'),
                    DeleteAction::make(),
                ])->label('More')->tooltip('More')->color('gray'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading('No clients yet')
            ->emptyStateDescription('A client groups its recordings and teaches the engines its names and words.')
            ->emptyStateActions([CreateAction::make()->label('Add a client')]);
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
