<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\RecordingRole;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\CaptureSession;
use App\Models\SentItem;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Projects\ProjectTree;
use App\Services\Sharing\Teammates;
use App\Services\TranscriptExporter;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\Workspaces;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use UnitEnum;

/**
 * Shared with me (v1.5): recordings and projects teammates sent the signed-in person, from any workspace. They can be
 * played and read here, saved into the current workspace as an independent copy, or taken off the list.
 */
class SharedWithMe extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'More';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Shared with me';

    /** Since v1.5 in the small console too (members and viewers), unless Settings closes the console to them. */
    public static function canAccess(): bool
    {
        return ConsoleAccess::open();
    }

    protected static ?string $slug = 'shared-with-me';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(Teammates::class)->receivedQuery($this->person())->with(['sender', 'captureSession', 'client']))
            ->defaultSort('sent_at', 'desc')
            ->description('Recordings and projects others sent you. Save one to keep your own copy in this workspace.')
            ->columns([
                TextColumn::make('title')->label('Title')->limit(60)->searchable()
                    ->description(fn (SentItem $item): string => $item->kind === 'project' ? 'Project' : 'Recording'),
                TextColumn::make('sender.name')->label('From')->description(fn (SentItem $item): string => $item->sender->email),
                TextColumn::make('note')->label('Note')->placeholder('—')->limit(80)->wrap(),
                TextColumn::make('sent_at')->label('Sent')->since()->sortable(),
                TextColumn::make('saved_at')->label('Saved')->since()->placeholder('Not yet'),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading(fn (SentItem $item): string => $item->title)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (SentItem $item): View => view('filament.pages.shared-preview', $this->preview($item))),
                Action::make('save')->label('Save to my library')->icon(Heroicon::OutlinedArrowDownTray)
                    // Since v1.5 a viewer opens what is shared with them and keeps no library.
                    ->visible(fn (): bool => app(Workspaces::class)->roleOf($this->person(), (int) CurrentWorkspace::id())?->records() === true)
                    ->requiresConfirmation()
                    ->modalHeading('Save a copy?')
                    ->modalDescription(fn (SentItem $item): string => $item->kind === 'project'
                        ? 'The project and its recordings are copied into this workspace. Your copy stays when the sender deletes theirs.'
                        : 'The recording, its audio and transcript are copied into this workspace\'s Inbox. Your copy stays when the sender deletes theirs.')
                    ->modalSubmitActionLabel('Save')
                    ->action(fn (SentItem $item) => $this->save($item)),
                Action::make('remove')->label('Remove')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->requiresConfirmation()->modalDescription('It goes off your list. The sender keeps theirs.')
                    ->action(function (SentItem $item): void {
                        app(Teammates::class)->remove($item);
                        Notification::make()->title('Removed from Shared with me')->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedInboxArrowDown)
            ->emptyStateHeading('Nothing shared with you yet')
            ->emptyStateDescription('When a teammate sends you a recording or a project, it appears here.');
    }

    private function save(SentItem $item): void
    {
        $workspace = CurrentWorkspace::id();
        if ($workspace === null || $workspace === CurrentWorkspace::NONE) {
            return;
        }
        try {
            $copy = app(Teammates::class)->save($this->person(), $workspace, $item);
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $url = isset($copy['client_id'])
            ? ClientResource::getUrl('view', ['record' => $copy['client_id']])
            : CaptureSessionResource::getUrl('view', ['record' => $copy['session_id']]);
        Notification::make()->title('Saved to your library')->body('Your copy is yours to edit, share or delete.')->success()
            ->actions([Action::make('openCopy')->label('Open the copy')->url($url)])->send();
    }

    /** @return array<string, mixed> what the preview shows: a recording's player and transcript, or a project's recordings */
    private function preview(SentItem $item): array
    {
        $teammates = app(Teammates::class);
        if ($item->kind === 'project') {
            return ['item' => $item, 'sessions' => $teammates->sharedSessions($this->person())
                ->whereIn('client_id', $item->client !== null ? app(ProjectTree::class)->withDescendants($item->client) : [])
                ->latest('started_at')->limit(200)->get(), 'lines' => [], 'audio' => null];
        }
        $session = $item->captureSession;
        if (! $session instanceof CaptureSession) {
            return ['item' => $item, 'sessions' => null, 'lines' => [], 'audio' => null];
        }
        $session = Teammates::readOnly($session);
        $transcript = $session->transcripts()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)->first();
        $primary = $session->recordings()->where('role', RecordingRole::Primary->value)->first();

        return [
            'item' => $item,
            'sessions' => null,
            'lines' => $transcript instanceof Transcript ? app(TranscriptExporter::class)->lines($transcript, $session) : [],
            'audio' => $primary !== null && $primary->isReady()
                ? URL::temporarySignedRoute('api.recordings.audio', now()->addMinutes((int) config('echoledger.playback_url_minutes', 30)), ['recording' => $primary->id])
                : null,
        ];
    }

    private function person(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
