<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\SharingActions;
use App\Models\Client;
use App\Models\RequestLink;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Sharing\RequestLinks;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Request links (v1.5): a page anyone with the link can send files to, without an account. Sound and video become
 * recordings in the chosen project (or the Inbox); with a project, documents and images go to its Files. The address
 * is shown once, when the link is made.
 */
class RequestLinksPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquareStack;

    protected static string|UnitEnum|null $navigationGroup = 'More';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Request links';

    protected static ?string $slug = 'request-links';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New request link')->icon(Heroicon::OutlinedPlus)
                ->modalDescription('Anyone with the link can send you files on a page of this server, without an account.')
                ->schema([
                    TextInput::make('title')->label('What you ask for')->placeholder(RequestLinks::DEFAULT_TITLE)->maxLength(200),
                    Select::make('client_id')->label('Project')->placeholder('Inbox (sound and video only)')->searchable()
                        ->options(fn (): array => SharingActions::folderOptions())
                        ->helperText('With a project, PDF, Word, text and image files go to its Files too.'),
                    Select::make('expires')->label('Open for')->default('604800')->selectablePlaceholder(false)
                        ->options(['86400' => '1 day', '604800' => '7 days', '2592000' => '30 days', 'never' => 'Until I revoke it']),
                    TextInput::make('max_files')->label('Files at most')->numeric()->integer()->default(20)->minValue(1)->maxValue(RequestLinks::MAX_FILES)->required(),
                    TextInput::make('max_mb')->label('Each file at most (MB)')->numeric()->integer()->default(100)->minValue(1)->maxValue(RequestLinks::maxMegabytes())->required(),
                ])
                ->modalSubmitActionLabel('Make the link')
                ->action(function (array $data): void {
                    $user = auth()->user();
                    $workspace = (int) CurrentWorkspace::id();
                    $project = filled($data['client_id'] ?? null) ? Client::query()->where('workspace_id', $workspace)->find($data['client_id']) : null;
                    if (! $user instanceof User || $workspace === CurrentWorkspace::NONE) {
                        return;
                    }
                    ['url' => $url] = app(RequestLinks::class)->create($workspace, $user->id, $project instanceof Client ? $project : null, $data['title'] ?? null,
                        ($data['expires'] ?? 'never') === 'never' ? null : now()->addSeconds((int) $data['expires']), (int) $data['max_files'], (int) $data['max_mb']);
                    Notification::make()->title('Request link ready. Copy it now: it is shown only once.')->body($url)->persistent()->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();

        return $table
            ->query(fn (): Builder => RequestLink::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->with('client'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Title')->limit(60)->searchable(),
                TextColumn::make('client.name')->label('Goes to')->placeholder('Inbox'),
                TextColumn::make('received')->label('Received')
                    ->state(fn (RequestLink $link): string => "{$link->received} of {$link->max_files}")
                    ->description(fn (RequestLink $link): string => "up to {$link->max_mb} MB each"),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (RequestLink $link): string => $link->revoked_at !== null ? 'Revoked' : ($link->isActive() ? 'Open' : 'Expired'))
                    ->color(fn (string $state): string => $state === 'Open' ? 'success' : 'gray'),
                TextColumn::make('expires_at')->label('Open until')->dateTime('M j, Y · g:i A', $tz)->placeholder('Until revoked'),
                TextColumn::make('created_at')->label('Made')->since(),
            ])
            ->recordActions([
                Action::make('revoke')->label('Revoke')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (RequestLink $link): bool => $link->isActive())
                    ->requiresConfirmation()->modalDescription('The page stops taking files at once. What was sent stays in the library.')
                    ->action(fn (RequestLink $link) => app(RequestLinks::class)->revoke($link)),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArrowDownOnSquareStack)
            ->emptyStateHeading('No request links yet')
            ->emptyStateDescription('Make one to collect voice notes, recordings or documents from anyone, without an account.');
    }
}
