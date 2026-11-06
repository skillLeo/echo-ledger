<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\SentItem;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Projects\ProjectTree;
use App\Services\SettingsRepository;
use App\Services\ShareLinks;
use App\Services\Sharing\Teammates;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\CurrentWorkspace;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Console actions shared by the recording page, the project page and the Projects list (v1.5): the share dialog with
 * its audiences, Send to a teammate, and the project's rename, move and delete. Each goes through the same services
 * as the device API.
 */
final class SharingActions
{
    /** The share dialog's expiry choices; `never` and `date` are the v1.5 additions. */
    public const EXPIRIES = ['3600' => '1 hour', '86400' => '1 day', '604800' => '7 days', 'never' => 'Never', 'date' => 'On a date…'];

    /**
     * "Share": a view-only link to a recording or a project, open to anyone, to a password, or to named people.
     *
     * @param  Closure(): (CaptureSession|Client)  $target
     * @param  Closure(): list<string>  $readyViews  the Show as views ready for a recording (none for a project)
     */
    public static function share(Closure $target, Closure $readyViews, ?Closure $hasAudio = null): Action
    {
        return Action::make('share')
            ->label('Share')->labeledFrom('sm')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->button()
            ->modalHeading(fn (): string => $target() instanceof Client ? 'Share this project' : 'Share this recording')
            ->modalDescription(fn (): string => $target() instanceof Client
                ? 'A view-only page listing the project\'s recordings (its subfolders\' too), each one readable and playable.'
                : 'A view-only page with the transcript and a player.')
            ->schema([
                Select::make('audience')->label('Who can open it')->required()->default('public')->selectablePlaceholder(false)->live()
                    ->options(['public' => 'Anyone with the link', 'password' => 'Anyone with the link and a password', 'people' => 'Only people I name (they sign in)']),
                TextInput::make('password')->label('Password')->password()->revealable()->minLength(6)->maxLength(100)
                    ->required(fn (Get $get): bool => $get('audience') === 'password')
                    ->visible(fn (Get $get): bool => $get('audience') === 'password'),
                TagsInput::make('people')->label('Their emails')->placeholder('name@example.com')
                    ->helperText('They open the link signed in to EchoLedger with one of these emails. Up to '.ShareLinks::MAX_PEOPLE.'.')
                    ->nestedRecursiveRules(['email', 'max:190'])->rules(['array', 'max:'.ShareLinks::MAX_PEOPLE])
                    ->required(fn (Get $get): bool => $get('audience') === 'people')
                    ->visible(fn (Get $get): bool => $get('audience') === 'people'),
                Select::make('expires')->label('Expires')->required()->default('604800')->selectablePlaceholder(false)->live()
                    ->options(self::EXPIRIES),
                DateTimePicker::make('expires_at')->label('Expires on')->seconds(false)->minDate(now())
                    ->timezone(app(SettingsRepository::class)->displayTimezone())
                    ->required(fn (Get $get): bool => $get('expires') === 'date')
                    ->visible(fn (Get $get): bool => $get('expires') === 'date'),
                Toggle::make('allow_download')->label('Allow downloading the audio and transcript')
                    ->helperText('Off: they can read and listen only.')
                    ->visible(fn (): bool => $hasAudio === null || $hasAudio()),
                Select::make('view')->label('Show the transcript as')->placeholder('Original words')
                    ->options(fn (): array => array_intersect_key(TranscriptViews::VIEWS, array_flip($readyViews())))
                    ->visible(fn (): bool => $readyViews() !== []),
            ])
            ->modalSubmitActionLabel('Make the link')
            ->action(function (array $data) use ($target): void {
                $expiresAt = match ((string) ($data['expires'] ?? '')) {
                    'never' => null,
                    'date' => filled($data['expires_at'] ?? null) ? Carbon::parse((string) $data['expires_at'])->utc() : null,
                    default => now()->addSeconds((int) $data['expires']),
                };
                try {
                    ['url' => $url] = app(ShareLinks::class)->make($target(), [
                        'audience' => (string) ($data['audience'] ?? 'public'),
                        'password' => isset($data['password']) ? (string) $data['password'] : null,
                        'people' => array_values((array) ($data['people'] ?? [])),
                        'include_audio' => true,
                        'allow_download' => (bool) ($data['allow_download'] ?? false),
                        'expires_at' => $expiresAt,
                        'view' => is_string($data['view'] ?? null) ? $data['view'] : null,
                    ], self::author());
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Share link ready. Copy it now: it is shown only once.')->body($url)->persistent()->success()->send();
            });
    }

    /**
     * "Send to a teammate": the recording or project shows up in Shared with me for accounts on this server.
     *
     * @param  Closure(): (CaptureSession|Client)  $target
     */
    public static function send(Closure $target): Action
    {
        return Action::make('send')
            ->label('Send to a teammate')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->modalDescription('They find it in Shared with me, can read and play it, and can save their own copy. People without an account here will not receive it.')
            ->schema([
                TagsInput::make('to')->label('Their emails')->placeholder('name@example.com')->required()
                    ->nestedRecursiveRules(['email', 'max:190'])->rules(['array', 'max:'.Teammates::MAX_RECIPIENTS]),
                Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500),
            ])
            ->modalSubmitActionLabel('Send')
            ->action(function (array $data) use ($target): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }
                try {
                    $sent = app(Teammates::class)->send($user, $target(), array_values((array) $data['to']), $data['note'] ?? null);
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title($sent === 1 ? 'Sent to 1 address' : "Sent to {$sent} addresses")->body('People without an account here will not receive it.')->success()->send();
            });
    }

    /**
     * "Sent to": whom this recording or project was sent to, with Stop sharing on each (the page's StopsSharing).
     *
     * @param  Closure(): (CaptureSession|Client)  $target
     */
    public static function sentTo(Closure $target): Action
    {
        return Action::make('sentTo')
            ->label('Sent to')
            ->icon(Heroicon::OutlinedUsers)
            ->color('gray')
            ->modalWidth('lg')
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(fn (): View => view('filament.pages.sent-to', ['items' => self::sentOf($target())]));
    }

    /** @return Collection<int, SentItem> */
    private static function sentOf(CaptureSession|Client $target): Collection
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(Teammates::class)->sentOf($user, $target->workspace_id, $target instanceof CaptureSession ? $target->id : null, $target instanceof Client ? $target->id : null)
            : new Collection;
    }

    /** Rename a project; names are unique within a folder. */
    public static function renameProject(): Action
    {
        return Action::make('renameProject')
            ->label('Rename')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalWidth('lg')
            ->fillForm(fn (Client $record): array => ['name' => $record->name])
            ->schema([TextInput::make('name')->label('Name')->required()->maxLength(120)->autofocus()])
            ->modalSubmitActionLabel('Save')
            ->action(function (Client $record, array $data, Action $action): void {
                $name = trim((string) $data['name']);
                $tree = app(ProjectTree::class);
                try {
                    // With the other changes of the workspace's projects waiting (ProjectTree::place).
                    $tree->place($record->workspace_id, function () use ($record, $name, $tree): Client {
                        $record->refresh();
                        $tree->assertNameFree($record->workspace_id, $record->parent_id, $name, $record->id);
                        $record->update(['name' => $name]);

                        return $record;
                    });
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }
                // Toasts render their title as HTML: a name is text.
                Notification::make()->title('Renamed to “'.e($name).'”')->success()->send();
            });
    }

    /** Move a project into another one, or to the top; never into itself or its own subfolders, at most four levels. */
    public static function moveProject(): Action
    {
        return Action::make('moveProject')
            ->label('Move')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalWidth('lg')
            ->fillForm(fn (Client $record): array => ['parent_id' => $record->parent_id])
            ->schema([
                Select::make('parent_id')->label('Move into')->placeholder('Top level (no folder)')->searchable()
                    ->options(fn (Client $record): array => self::folderOptions($record)),
            ])
            ->modalSubmitActionLabel('Move')
            ->action(function (Client $record, array $data, Action $action): void {
                $tree = app(ProjectTree::class);
                try {
                    // With the other changes of the workspace's projects waiting (ProjectTree::place).
                    $tree->place($record->workspace_id, function () use ($record, $data, $tree): Client {
                        $record->refresh();
                        $parent = $tree->parentFor($record->workspace_id, $data['parent_id'] ?? null);
                        $tree->assertCanPlace($record, $parent);
                        $tree->assertNameFree($record->workspace_id, $parent?->id, $record->name, $record->id);
                        $record->update(['parent_id' => $parent?->id]);

                        return $record;
                    });
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }
                $parent = $record->parent_id !== null ? $record->parent()->first() : null;
                Notification::make()->title($parent === null ? 'Moved to the top level' : 'Moved into “'.e($parent->name).'”')->success()->send();
            });
    }

    /** Delete a project: its recordings go to the Inbox, its subfolders up a level. Owners only. */
    public static function deleteProject(?Closure $after = null): Action
    {
        return Action::make('deleteProject')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Client $record): string => 'Delete “'.$record->name.'”?')
            ->modalDescription('Its recordings are not deleted: they go to the Inbox. Its subfolders move up a level. Its files, brief, notes, memory, rules and share links are deleted.')
            ->modalSubmitActionLabel('Delete project')
            ->visible(fn (Client $record): bool => ($user = auth()->user()) instanceof User && app(ProjectTree::class)->canDelete($user, $record))
            ->action(function (Client $record) use ($after): void {
                $user = auth()->user();
                try {
                    $moved = app(ProjectTree::class)->delete($record, $user instanceof User ? $user : null);
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Project deleted')
                    ->body(($moved['moved_sessions'] === 1 ? '1 recording' : $moved['moved_sessions'].' recordings').' moved to the Inbox.')->success()->send();
                if ($after !== null) {
                    $after();
                }
            });
    }

    /**
     * The projects a project can move into, labelled with their path ("Acme › Website"): not itself or its subfolders.
     *
     * @return array<string, string>
     */
    public static function folderOptions(?Client $moving = null): array
    {
        $workspace = (int) CurrentWorkspace::id();
        $tree = app(ProjectTree::class);
        $exclude = $moving !== null ? $tree->withDescendants($moving) : [];
        $nodes = Client::query()->where('workspace_id', $workspace)->where('user_id', CurrentWorkspace::userId())->get(['id', 'parent_id', 'name'])
            ->mapWithKeys(fn (Client $c): array => [$c->id => ['parent_id' => $c->parent_id, 'name' => $c->name]])->all();
        $options = [];
        foreach ($nodes as $id => $node) {
            if (in_array($id, $exclude, true)) {
                continue;
            }
            $options[$id] = implode(' › ', [...array_column(ProjectTree::pathOf((string) $id, $nodes), 'name'), $node['name']]);
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** Who made a link, as the access log shows it. */
    public static function author(): ?string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->name : null;
    }

    /** "Anyone", "Password" or the people of a link, for the Shares list. */
    public static function audienceLabel(ShareLink $link): string
    {
        return match ($link->audience) {
            'password' => 'Password',
            'people' => implode(', ', $link->people ?? []),
            default => 'Anyone with the link',
        };
    }
}
