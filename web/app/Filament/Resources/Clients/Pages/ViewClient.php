<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Pages;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\ApiCredentials;
use App\Filament\Concerns\DropsRecordings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\PlanLock;
use App\Filament\Support\RecordingRow;
use App\Filament\Support\SharingActions;
use App\Filament\Support\Shell;
use App\Filament\Support\StopsSharing;
use App\Jobs\RefreshClientBriefJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\FilingRule;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Projects\FilingRules;
use App\Services\Projects\ProjectTree;
use App\Services\SettingsRepository;
use App\Services\TranscriptExporter;
use App\Services\Workspaces\PlanLimits;
use App\Support\UrduText;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A project's page (v1.4 client workspace, a project since v1.5), as the apps show it: Overview (AI notes, the budget,
 * the brief, the memory, the latest call notes, Copy for AI), Recordings (its subfolders, search and sort), Files
 * (documents and pasted instructions), Ask and Rules (filing rules). Everything goes through the same services as the
 * API: App\Services\ClientAi\ClientWorkspace, App\Services\Projects\ProjectTree and FilingRules.
 */
class ViewClient extends ViewRecord
{
    use DropsRecordings, StopsSharing, WithFileUploads;

    protected static string $resource = ClientResource::class;

    public const TABS = ['overview' => 'Overview', 'recordings' => 'Recordings', 'screens' => 'Screens', 'files' => 'Files', 'ask' => 'Ask', 'rules' => 'Rules'];

    /** The Screens tab (v1.5) shows the screens of this many recordings, newest first. */
    public const SCREEN_RECORDINGS = 30;

    /** How the Recordings tab sorts (v1.5). */
    public const SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'By title', 'longest' => 'Longest first'];

    /** Rule kinds as the Rules tab offers them. */
    public const RULE_KINDS = ['title' => 'Title contains', 'app' => 'App is', 'contact' => 'Caller is (phone or email)'];

    /** The memory, grouped as the apps show it. */
    public const MEMORY_GROUPS = ['decision' => 'Decisions', 'question' => 'Open questions', 'change' => 'Changes', 'scope' => 'Scope'];

    /** How often the page refreshes while the brief, a file or call notes are being made. */
    public const POLL = '5s';

    #[Url]
    public string $tab = 'overview';

    /** @var TemporaryUploadedFile|null a document dropped or chosen on Files */
    public $upload = null;

    public string $instructions = '';

    public string $instructionsName = '';

    public string $memoryKind = 'decision';

    public string $memoryText = '';

    public string $question = '';

    /** Search in the project's recordings (v1.5): title, caller or transcript words. */
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sort = 'newest';

    public string $ruleKind = 'title';

    public string $ruleValue = '';

    public string $askLanguage = 'en';

    /** @var array{answer: string, citations: list<array<string, mixed>>}|null */
    public ?array $answer = null;

    public ?string $askError = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }
    }

    public function getTitle(): string
    {
        return $this->client()->name;
    }

    public function getSubheading(): string
    {
        $count = $this->client()->captureSessions()->count();
        $last = $this->client()->captureSessions()->max('started_at');
        $zone = app(SettingsRepository::class)->displayTimezone();

        return match (true) {
            $count === 0 => 'No recordings yet',
            default => ($count === 1 ? '1 recording' : "{$count} recordings").' · Last call '.Carbon::parse((string) $last, 'UTC')->setTimezone($zone)->format('M j'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            SharingActions::share(fn (): Client => $this->client(), fn (): array => []),
            ActionGroup::make([
                Action::make('newSubfolder')->label('New subfolder')->icon(Heroicon::OutlinedFolderPlus)
                    ->url(fn (): string => ClientResource::getUrl('create', ['parent' => $this->client()->id]))
                    ->visible(fn (): bool => $this->depth() < Client::MAX_DEPTH),
                SharingActions::send(fn (): Client => $this->client()),
                SharingActions::sentTo(fn (): Client => $this->client()),
                SharingActions::renameProject(),
                SharingActions::moveProject(),
                EditAction::make()->label('Settings')->icon(Heroicon::OutlinedCog6Tooth),
                SharingActions::deleteProject(fn () => $this->redirect(ClientResource::getUrl('index'))),
            ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        // Since v1.6 (P4.2): the projects in a column beside the project, as in the desktop app.
        return $schema->components([
            Group::make([
                View::make('filament.shell.column')->viewData(fn (): array => ['projects' => Shell::projects(), 'currentProject' => (string) $this->client()->id]),
                View::make('filament.clients.workspace')->viewData(fn (): array => $this->workspaceData()),
            ])->extraAttributes(['class' => 'el-with-col el-project-with-col']),
        ]);
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
        }
    }

    // ---- Overview ----------------------------------------------------------------------------------------------

    /** The upgrade sheet for AI notes (since v1.6): what they do, the plans side by side, Request upgrade. */
    public function upgradeAiNotesAction(): Action
    {
        return PlanLock::sheet('upgradeAiNotes', 'ai_notes', 'AI notes');
    }

    /** AI notes on or off: off means no Claude request is made for this client. */
    public function toggleAiNotes(): void
    {
        $client = $this->client();
        $client->update(['ai_notes' => ! $client->ai_notes]);
        Notification::make()->title($client->ai_notes ? 'AI notes are on for '.$client->name : 'AI notes are off for '.$client->name)->success()->send();
    }

    /** The edit form of the brief: the goal on one line, every other section one line per item. */
    public function editBriefAction(): Action
    {
        return Action::make('editBrief')
            ->label('Edit')
            ->modalHeading('Edit the brief')
            ->modalDescription('Once you edit the brief it is not rewritten by itself; Make again replaces it.')
            ->modalWidth('2xl')
            ->fillForm(function (): array {
                $sections = ClientBrief::clean($this->brief()->sections);
                $data = ['goal' => $sections['goal']];
                foreach (array_slice(ClientBrief::SECTIONS, 1) as $key) {
                    $data[$key] = implode("\n", $sections[$key]);
                }

                return $data;
            })
            ->schema([
                TextInput::make('goal')->label('Goal')->maxLength(300),
                ...array_map(fn (string $key): Textarea => Textarea::make($key)->label(ucfirst($key))->rows(3)
                    ->helperText($key === 'scope' ? 'One line per item.' : null), array_slice(ClientBrief::SECTIONS, 1)),
            ])
            ->modalSubmitActionLabel('Save')
            ->action(function (array $data): void {
                $sections = ['goal' => (string) ($data['goal'] ?? '')];
                foreach (array_slice(ClientBrief::SECTIONS, 1) as $key) {
                    $sections[$key] = preg_split('/\R/u', (string) ($data[$key] ?? '')) ?: [];
                }
                $client = $this->client();
                $brief = ClientBrief::query()->firstOrNew(['client_id' => $client->id]);
                $brief->fill(['workspace_id' => $client->workspace_id, 'sections' => ClientBrief::clean($sections), 'edited' => true, 'status' => 'ready', 'error' => null])->save();
                Notification::make()->title('Brief saved')->success()->send();
            });
    }

    /** Make again: from the files (or the calls), replacing an edited brief; done on the queue. */
    public function remakeBrief(): void
    {
        $client = $this->client();
        try {
            app(ClientWorkspace::class)->assertCanWriteNotes($client->workspace_id);
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return;
        }
        $brief = ClientBrief::query()->firstOrNew(['client_id' => $client->id]);
        $brief->fill(['workspace_id' => $client->workspace_id, 'sections' => $brief->sections ?? ClientBrief::emptySections(), 'status' => 'working', 'error' => null])->save();
        RefreshClientBriefJob::dispatch($client->id, true);
    }

    public function addMemory(): void
    {
        $this->validate([
            'memoryKind' => ['required', 'in:'.implode(',', ClientMemoryItem::KINDS)],
            'memoryText' => ['required', 'string', 'max:500'],
        ], [], ['memoryText' => 'line', 'memoryKind' => 'kind']);
        $client = $this->client();
        if (! app(PlanLimits::class)->allows($client->workspace_id, 'ai_notes')) {
            Notification::make()->title(PlanLimits::FEATURE_MESSAGES['ai_notes'])->warning()->send();

            return;
        }
        ClientMemoryItem::query()->create([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->id,
            'kind' => $this->memoryKind,
            'text' => trim($this->memoryText),
            'happened_on' => now(app(SettingsRepository::class)->displayTimezone())->toDateString(),
            'pinned' => false,
            'source_type' => 'user',
        ]);
        $this->memoryText = '';
    }

    public function togglePinMemory(string $id): void
    {
        $item = $this->memoryItem($id);
        $item?->update(['pinned' => ! $item->pinned]);
    }

    public function removeMemory(string $id): void
    {
        $this->memoryItem($id)?->delete();
    }

    /** "Copy for AI": the brief, the memory and the latest call notes as one text. */
    public function bundle(): string
    {
        return app(ClientWorkspace::class)->bundle($this->client());
    }

    // ---- Files -------------------------------------------------------------------------------------------------

    /** A document dropped or chosen: stored encrypted, its text read on the queue. */
    public function updatedUpload(): void
    {
        $this->validate(['upload' => ['required', 'file', 'max:'.(ClientWorkspace::MAX_FILE_BYTES / 1024)]], [], ['upload' => 'file']);
        $upload = $this->upload;
        if (! $upload instanceof TemporaryUploadedFile) {
            return;
        }
        try {
            $file = app(ClientWorkspace::class)->addFile($this->client(), $upload, (int) auth()->id());
            Notification::make()->title('Added '.$file->name)->body('Its text is being read for the brief and Ask.')->success()->send();
        } catch (ApiError $e) {
            $this->addError('upload', $e->getMessage());
        } finally {
            $upload->delete();
            $this->upload = null;
        }
    }

    public function addInstructions(): void
    {
        $this->validate([
            'instructions' => ['required', 'string', 'max:100000'],
            'instructionsName' => ['nullable', 'string', 'max:200'],
        ], [], ['instructions' => 'instructions', 'instructionsName' => 'name']);
        app(ClientWorkspace::class)->addInstructions($this->client(), $this->instructions, $this->instructionsName ?: null, (int) auth()->id());
        $this->instructions = '';
        $this->instructionsName = '';
        Notification::make()->title('Instructions added')->success()->send();
    }

    public function removeFileAction(): Action
    {
        return Action::make('removeFile')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading('Remove this file?')
            ->modalDescription('The file and the text read from it are deleted. The brief keeps what it has until it is made again.')
            ->modalSubmitActionLabel('Remove')
            ->action(function (array $arguments): void {
                $file = ClientFile::query()->where('client_id', $this->client()->id)->find($arguments['file'] ?? null);
                if ($file instanceof ClientFile) {
                    app(ClientWorkspace::class)->deleteFile($file);
                    Notification::make()->title('Removed '.$file->name)->success()->send();
                }
            });
    }

    // ---- Ask ---------------------------------------------------------------------------------------------------

    public function ask(): void
    {
        $this->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'askLanguage' => ['required', 'in:en,roman_urdu'],
        ], [], ['question' => 'question']);
        $this->askError = null;
        try {
            $this->answer = app(ClientWorkspace::class)->ask($this->client(), trim($this->question), $this->askLanguage);
        } catch (ApiError $e) {
            $this->answer = null;
            $this->askError = $e->getMessage();
        }
    }

    // ---- Rules (v1.5) ------------------------------------------------------------------------------------------

    public function addRule(): void
    {
        $this->validate([
            'ruleKind' => ['required', 'in:'.implode(',', FilingRule::KINDS)],
            'ruleValue' => ['required', 'string', 'max:120'],
        ], [], ['ruleValue' => 'value', 'ruleKind' => 'kind']);
        try {
            ['created' => $created] = app(FilingRules::class)->add($this->client(), $this->ruleKind, $this->ruleValue);
        } catch (ApiError $e) {
            $this->addError('ruleValue', $e->getMessage());

            return;
        }
        $this->ruleValue = '';
        Notification::make()->title($created ? 'Rule added' : 'This project already has that rule')->success()->send();
    }

    public function removeRule(int $id): void
    {
        $rule = FilingRule::query()->where('client_id', $this->client()->id)->find($id);
        if ($rule instanceof FilingRule) {
            app(FilingRules::class)->remove($rule);
        }
    }

    // ---- State -------------------------------------------------------------------------------------------------

    /** The brief is being made, a file read or call notes written: the page refreshes until they are done. */
    public function hasPending(): bool
    {
        $client = $this->client();

        return $this->brief()->status === 'working'
            || ClientFile::query()->where('client_id', $client->id)->where('status', 'extracting')->exists()
            || CallNote::query()->ofProjectMaker($client)->where('status', 'pending')->exists();
    }

    /** The poll: a re-render shows what is done. */
    public function refreshWorkspace(): void {}

    /** @return array<string, mixed> */
    private function workspaceData(): array
    {
        $client = $this->client();
        $service = app(ClientWorkspace::class);
        $zone = app(SettingsRepository::class)->displayTimezone();
        $data = [
            'client' => $client,
            'tab' => $this->tab,
            'tabs' => self::TABS,
            // Since v1.6 (P4.4): the tabs with their counts, as in the desktop app.
            'tabCounts' => [
                'recordings' => $client->captureSessions()->count(),
                'files' => ClientFile::query()->where('client_id', $client->id)->count(),
                'rules' => FilingRule::query()->where('client_id', $client->id)->count(),
            ],
            'pending' => $this->hasPending(),
            'poll' => self::POLL,
            'zone' => $zone,
            // Since v1.6 (P1.4): the plan's badge and the upgrade sheet when the plan leaves AI notes out.
            'aiLocked' => ! PlanLock::allows('ai_notes'),
            'aiBadge' => PlanLock::badge('ai_notes'),
        ];

        return $data + match ($this->tab) {
            'recordings' => [
                'recordings' => $this->recordings()->with(['finalTranscript', 'client', 'labels', 'device'])->limit(100)->get()
                    ->map(fn (CaptureSession $s): array => RecordingRow::for($s) + ['url' => CaptureSessionResource::getUrl('view', ['record' => $s])])->all(),
                'subfolders' => Client::query()->where('parent_id', $client->id)->withCount('captureSessions')->orderBy('name')->get(),
                'sorts' => self::SORTS,
                'libraryUrl' => CaptureSessionResource::getUrl('index', ['tableFilters' => ['client' => ['value' => $client->id]]])],
            'rules' => ['rules' => FilingRule::query()->where('client_id', $client->id)->orderBy('id')->get(), 'kinds' => self::RULE_KINDS,
                'apps' => collect(SourceApp::cases())->mapWithKeys(fn (SourceApp $a): array => [$a->value => $a->getLabel()])->all()],
            'files' => ['files' => ClientFile::query()->where('client_id', $client->id)->latest()->get()],
            'screens' => ['groups' => $this->screenGroups($client, $zone), 'limit' => self::SCREEN_RECORDINGS],
            'ask' => ['configured' => $service->configured(), 'credentialsUrl' => ApiCredentials::canAccess() ? ApiCredentials::getUrl(panel: 'admin') : null],
            default => [
                'configured' => $service->configured(),
                'credentialsUrl' => ApiCredentials::canAccess() ? ApiCredentials::getUrl(panel: 'admin') : null,
                'budget' => $service->budget($client->workspace_id),
                'clientSpend' => $service->clientSpend($client),
                'brief' => $this->brief(),
                'memory' => ClientMemoryItem::query()->ofProjectMaker($client)->orderByDesc('pinned')->orderByDesc('happened_on')->orderByDesc('created_at')
                    ->limit(300)->get()->groupBy('kind'),
                'notes' => CallNote::query()->ofProjectMaker($client)->with('captureSession')->latest()->limit(5)->get(),
            ],
        };
    }

    private function brief(): ClientBrief
    {
        $brief = ClientBrief::query()->where('client_id', $this->client()->id)->first();

        return $brief instanceof ClientBrief ? $brief : new ClientBrief(['sections' => ClientBrief::emptySections(), 'edited' => false, 'status' => 'ready']);
    }

    private function memoryItem(string $id): ?ClientMemoryItem
    {
        $item = ClientMemoryItem::query()->ofProjectMaker($this->client())->find($id);

        return $item instanceof ClientMemoryItem ? $item : null;
    }

    private function client(): Client
    {
        /** @var Client $client */
        $client = $this->getRecord();

        return $client;
    }

    /**
     * The Recordings tab's list: the project's own recordings, narrowed by the search and in the chosen order.
     *
     * @return Builder<CaptureSession>
     */
    private function recordings(): Builder
    {
        $query = CaptureSession::query()->where('workspace_id', $this->client()->workspace_id)->where('user_id', $this->client()->user_id)->where('client_id', $this->client()->id);
        $search = trim($this->search);
        if ($search !== '') {
            $pattern = '%'.strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn (Builder $q) => CaptureSessionsTable::searchDisplayedTitle($q, $search)
                ->orWhereRaw("LOWER(caller_name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('finalTranscript', fn (Builder $t) => $t->where('search_text', 'like', '%'.UrduText::fold($search).'%')));
        }

        return match ($this->sort) {
            'oldest' => $query->oldest('started_at'),
            'title' => $query->orderByRaw('LOWER(COALESCE(title, caller_name, source_app))'),
            'longest' => $query->orderByDesc('duration_ms'),
            default => $query->latest('started_at'),
        };
    }

    /**
     * The Screens tab (v1.5): the recordings of the project and its subfolders that kept screens, newest first, each
     * with its screens in time order (numbered as the API numbers them).
     *
     * @return list<array{title: string, when: string, url: string, screens: list<array{id: string, index: int, at_ms: int, clock: string, thumb: string, image: string, description: string|null, url: string}>}>
     */
    private function screenGroups(Client $client, string $zone): array
    {
        $sessions = CaptureSession::query()->where('workspace_id', $client->workspace_id)->where('user_id', $client->user_id)
            ->whereIn('client_id', app(ProjectTree::class)->withDescendants($client))
            ->where('status', '!=', SessionStatus::Recording->value)->whereHas('screens')
            ->latest('started_at')->limit(self::SCREEN_RECORDINGS)->get();
        $screens = Screen::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('capture_session_id', $sessions->modelKeys())
            ->orderBy('at_ms')->orderBy('id')->get(['id', 'capture_session_id', 'at_ms', 'description'])->groupBy('capture_session_id');

        return array_values($sessions->map(function (CaptureSession $session) use ($screens, $zone): array {
            $url = CaptureSessionResource::getUrl('view', ['record' => $session]);

            return [
                'title' => $session->displayTitle(),
                'when' => $session->started_at->copy()->setTimezone($zone)->format('M j, Y · g:i A'),
                'url' => $url,
                'screens' => array_values(($screens[$session->id] ?? collect())->values()->map(fn (Screen $s, int $i): array => [
                    'id' => $s->id,
                    'index' => $i + 1,
                    'at_ms' => $s->at_ms,
                    'clock' => TranscriptExporter::clock($s->at_ms),
                    'thumb' => route('admin.screens.media', [$s->id, 'thumb']),
                    'image' => route('admin.screens.media', [$s->id, 'image']),
                    'description' => $s->description,
                    'url' => $url.'?t='.$s->at_ms,
                ])->all()),
            ];
        })->all());
    }

    /** Levels from the top down to this project: 1 at the top. */
    private function depth(): int
    {
        return ProjectTree::depth($this->client()->id, app(ProjectTree::class)->parents($this->client()->workspace_id, $this->client()->user_id));
    }

    /** "Projects > Acme > Website > Name": the folders above it, each a link. */
    public function getBreadcrumbs(): array
    {
        $client = $this->client();
        $nodes = Client::query()->where('workspace_id', $client->workspace_id)->where('user_id', $client->user_id)->get(['id', 'parent_id', 'name'])
            ->mapWithKeys(fn (Client $c): array => [$c->id => ['parent_id' => $c->parent_id, 'name' => $c->name]])->all();
        $crumbs = [ClientResource::getUrl('index') => 'Projects'];
        foreach (ProjectTree::pathOf($client->id, $nodes) as $ancestor) {
            $crumbs[ClientResource::getUrl('view', ['record' => $ancestor['id']])] = $ancestor['name'];
        }

        return [...$crumbs, $client->name];
    }
}
