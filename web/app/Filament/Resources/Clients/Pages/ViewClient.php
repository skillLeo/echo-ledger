<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Pages;

use App\Exceptions\ApiError;
use App\Filament\Pages\ApiCredentials;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\RecordingRow;
use App\Jobs\RefreshClientBriefJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A client's workspace (v1.4, P4), as the apps show it: Overview (AI notes, the budget, the brief, the memory, the
 * latest call notes, Copy for AI), Recordings, Files (documents and pasted instructions) and Ask. Everything goes
 * through App\Services\ClientAi\ClientWorkspace, like the API.
 */
class ViewClient extends ViewRecord
{
    use WithFileUploads;

    protected static string $resource = ClientResource::class;

    public const TABS = ['overview' => 'Overview', 'recordings' => 'Recordings', 'files' => 'Files', 'ask' => 'Ask'];

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
            EditAction::make()->label('Settings')->icon(Heroicon::OutlinedCog6Tooth)->color('gray'),
            DeleteAction::make()->iconButton()->icon(Heroicon::OutlinedTrash),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.clients.workspace')->viewData(fn (): array => $this->workspaceData()),
        ]);
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
        }
    }

    // ---- Overview ----------------------------------------------------------------------------------------------

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
            app(ClientWorkspace::class)->assertCanSpend($client->workspace_id);
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

    // ---- State -------------------------------------------------------------------------------------------------

    /** The brief is being made, a file read or call notes written: the page refreshes until they are done. */
    public function hasPending(): bool
    {
        $client = $this->client();

        return $this->brief()->status === 'working'
            || ClientFile::query()->where('client_id', $client->id)->where('status', 'extracting')->exists()
            || CallNote::query()->where('client_id', $client->id)->where('status', 'pending')->exists();
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
            'pending' => $this->hasPending(),
            'poll' => self::POLL,
            'zone' => $zone,
        ];

        return $data + match ($this->tab) {
            'recordings' => ['recordings' => CaptureSession::query()->where('client_id', $client->id)->with(['finalTranscript', 'client', 'labels', 'device'])
                ->latest('started_at')->limit(100)->get()->map(fn (CaptureSession $s): array => RecordingRow::for($s) + [
                    'url' => CaptureSessionResource::getUrl('view', ['record' => $s]),
                ])->all(),
                'libraryUrl' => CaptureSessionResource::getUrl('index', ['tableFilters' => ['client' => ['value' => $client->id]]])],
            'files' => ['files' => ClientFile::query()->where('client_id', $client->id)->latest()->get()],
            'ask' => ['configured' => $service->configured(), 'credentialsUrl' => ApiCredentials::getUrl()],
            default => [
                'configured' => $service->configured(),
                'credentialsUrl' => ApiCredentials::getUrl(),
                'budget' => $service->budget($client->workspace_id),
                'clientSpend' => $service->clientSpend($client),
                'brief' => $this->brief(),
                'memory' => ClientMemoryItem::query()->where('client_id', $client->id)->orderByDesc('pinned')->orderByDesc('happened_on')->orderByDesc('created_at')
                    ->limit(300)->get()->groupBy('kind'),
                'notes' => CallNote::query()->where('client_id', $client->id)->with('captureSession')->latest()->limit(5)->get(),
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
        $item = ClientMemoryItem::query()->where('client_id', $this->client()->id)->find($id);

        return $item instanceof ClientMemoryItem ? $item : null;
    }

    private function client(): Client
    {
        /** @var Client $client */
        $client = $this->getRecord();

        return $client;
    }

    /** "Clients > Name", without a trailing "View". */
    public function getBreadcrumbs(): array
    {
        return $this->getResourceBreadcrumbs();
    }
}
