<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\RecordingRole;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\PersonAvatar;
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
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Shared with me (v1.5): recordings and projects teammates sent the signed-in person, from any workspace. They can be
 * played and read here, saved into the current workspace as an independent copy, or taken off the list.
 */
class SharedWithMe extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Users;

    protected static string|UnitEnum|null $navigationGroup = 'More';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Shared with me';

    /** In the console's language (the same English words in classic). */
    public function getTitle(): string|Htmlable
    {
        return __('Shared with me');
    }

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
            ->description(__('Recordings and projects others sent you. Save one to keep your own copy in this workspace.'))
            ->columns([
                TextColumn::make('title')->label(__('Title'))->limit(60)->searchable()
                    ->description(fn (SentItem $item): string => $item->kind === 'project' ? __('Project') : __('Recording')),
                // DES-08: in the new console the sender's avatar is beside their name, their email under it. "From" is the
                // person who shared it, a word of its own (lang/*/shared_context.php), not ur.json's "From" (a source).
                ConsoleV2::any()
                    ? TextColumn::make('sender.name')->label(__('shared_context.col_from'))->formatStateUsing(fn (SentItem $item): HtmlString => PersonAvatar::cell($item->sender, $item->sender->email))
                    : TextColumn::make('sender.name')->label(__('shared_context.col_from'))->description(fn (SentItem $item): string => $item->sender->email),
                TextColumn::make('note')->label(__('Note'))->placeholder('—')->limit(80)->wrap(),
                TextColumn::make('sent_at')->label(__('Sent'))->since()->sortable(),
                TextColumn::make('saved_at')->label(__('Saved'))->since()->placeholder(__('Not yet')),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open'))->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading(fn (SentItem $item): string => $item->title)
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (SentItem $item): View => view('filament.pages.shared-preview', $this->preview($item))),
                Action::make('save')->label(__('Save to my library'))->icon(Heroicon::OutlinedArrowDownTray)
                    // Since v1.5 a viewer opens what is shared with them and keeps no library.
                    ->visible(fn (): bool => app(Workspaces::class)->roleOf($this->person(), (int) CurrentWorkspace::id())?->records() === true)
                    ->requiresConfirmation()
                    ->modalHeading(__('Save a copy?'))
                    ->modalDescription(fn (SentItem $item): string => $item->kind === 'project'
                        ? __('The project and its recordings are copied into this workspace. Your copy stays when the sender deletes theirs.')
                        : (ConsoleV2::library()
                            ? __('The recording, its audio and transcript are copied into this workspace\'s recordings, with no project. Your copy stays when the sender deletes theirs.')
                            : __('The recording, its audio and transcript are copied into this workspace\'s Inbox. Your copy stays when the sender deletes theirs.')))
                    ->modalSubmitActionLabel(__('Save'))
                    ->action(fn (SentItem $item) => $this->save($item)),
                Action::make('remove')->label(__('Remove'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->requiresConfirmation()->modalDescription(__('It goes off your list. The sender keeps theirs.'))
                    ->action(function (SentItem $item): void {
                        app(Teammates::class)->remove($item);
                        Notification::make()->title(__('Removed from Shared with me'))->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(__('Nothing shared with you yet'))
            ->emptyStateDescription(__('When a teammate sends you a recording or a project, it appears here.'));
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
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

            return;
        }
        $url = isset($copy['client_id'])
            ? ClientResource::getUrl('view', ['record' => $copy['client_id']])
            : CaptureSessionResource::getUrl('view', ['record' => $copy['session_id']]);
        // The whole notice opens the copy (since v1.6.1), so it needs no button of its own.
        Notification::make()->title(__('Saved to your library'))->body(__('Your copy is yours to edit, share or delete. Click to open it.'))->success()
            ->url($url)->send();
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
