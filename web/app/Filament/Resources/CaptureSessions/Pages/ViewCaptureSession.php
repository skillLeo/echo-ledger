<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\ApiCredentials;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\PlanLock;
use App\Filament\Support\RecordingProgress;
use App\Filament\Support\RecordingRow;
use App\Filament\Support\SendsToWhatsApp;
use App\Filament\Support\SharingActions;
use App\Filament\Support\Shell;
use App\Filament\Support\StopsSharing;
use App\Filament\Support\WhatsAppActions;
use App\Http\Controllers\AdminMediaController;
use App\Jobs\MakeCallNotesJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\SessionOpen;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Accuracy\AccuracyLab;
use App\Services\Accuracy\ReferenceText;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\EngineComparison;
use App\Services\EngineWait;
use App\Services\FinalTranscriptCoordinator;
use App\Services\Library\ReadyNotices;
use App\Services\LibraryOrganiser;
use App\Services\Retranscriber;
use App\Services\SettingsRepository;
use App\Services\Sharing\WhatsAppMessage;
use App\Services\TextReplacements;
use App\Services\TranscriptDiff;
use App\Services\TranscriptEditor;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\ConsoleAccess;
use App\Stt\ChannelLanguage;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use App\Stt\ScriptCheck;
use App\Support\UnsureMarkers;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class ViewCaptureSession extends ViewRecord
{
    use SendsToWhatsApp;
    use StopsSharing;

    protected static string $resource = CaptureSessionResource::class;

    /** Transcript files offered under Download, in this order; PDF is the print page the browser saves as PDF. */
    public const DOWNLOAD_FORMATS = [
        'txt' => 'Text (.txt)',
        'docx' => 'Word (.docx)',
        'html' => 'PDF (print)',
        'srt' => 'Subtitles (.srt)',
        'json' => 'JSON (.json)',
        'md' => 'Markdown (.md)',
        'vtt' => 'Web subtitles (.vtt)',
    ];

    /** The Show as views are being made on the queue: the page polls until they are, then hands them to the player. */
    public bool $viewsMaking = false;

    /**
     * Since v1.5 (Phase 1C): the slim "Improving Roman Urdu… 60 %" bar while the view the page opens in is made; it
     * stays (as "Roman Urdu is ready") once the view is in, so the transcript below never moves.
     *
     * @var array{label: string, percent: int}|null
     */
    public ?array $viewsBar = null;

    /** Opening the page counts as opening the recording (the apps stop showing it as new for this user). */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $user = auth()->user();
        if ($user !== null) {
            SessionOpen::mark((string) $this->getRecord()->getKey(), (int) $user->getAuthIdentifier());
        }
        $transcript = $this->editableTranscript();
        $this->viewsMaking = $transcript !== null && app(TranscriptViews::class)->state($transcript)['status'] === 'making';
        $this->viewsBar = RecordingProgress::improvingBar($this->session());
        // Send to WhatsApp sends the view being read: the one the player opens in, until it says otherwise.
        $this->readingView = WhatsAppMessage::defaultView($this->session()) ?? 'original';
    }

    public function getTitle(): string
    {
        return $this->session()->displayTitle();
    }

    /** The title with its Rename button; the browser tab keeps the plain title. */
    public function getHeading(): Htmlable
    {
        return new HtmlString(view('filament.library.editable-title', ['title' => $this->session()->displayTitle()])->render());
    }

    /** Under the title, as on the phone: the app and device, then the day, time, length and client. */
    public function getSubheading(): Htmlable
    {
        $session = $this->session();
        $at = $session->started_at->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone());

        return new HtmlString(view('filament.recording.subheading', [
            'row' => RecordingRow::for($session),
            'when' => implode(' · ', array_filter([$at->format('M j'), $at->format(Shell::timeFormat()), CaptureSessionsTable::duration($session->duration_ms), $session->client?->name])),
        ])->render());
    }

    /**
     * Live status above the transcript, only while the recording is uploading or being transcribed; a quiet poll
     * while call notes are written or Show as views made.
     */
    public function content(Schema $schema): Schema
    {
        if ($this->hasCombinedRelationManagerTabsWithContent()) {
            return parent::content($schema);
        }

        return $schema->components([
            ViewComponent::make('filament.library.live-status')
                ->visible(fn (): bool => CaptureSessionsTable::isPending($this->session()))
                ->viewData(fn (): array => ['interval' => CaptureSessionsTable::LIVE_POLL, 'message' => $this->liveMessage(), 'progress' => RecordingProgress::for($this->session()),
                    'notify' => ['asked' => $this->notifyAsked()]]),
            ViewComponent::make('filament.recording.work-poll')
                ->visible(fn (): bool => ! CaptureSessionsTable::isPending($this->session()) && ($this->hasWorkInProgress() || $this->viewsBar !== null))
                ->viewData(fn (): array => ['interval' => CaptureSessionsTable::LIVE_POLL, 'bar' => $this->viewsBar]),
            $this->getInfolistContentComponent(),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /**
     * The live-status poll. Livewire re-renders the page with the new status; the player is not re-rendered, so it is
     * sent the transcript once nothing is pending any more (the poll then stops with the status line).
     */
    public function refreshLiveStatus(): void
    {
        $session = $this->session()->refresh();
        if (CaptureSessionsTable::isPending($session)) {
            return;
        }
        // The transcript is readable at once; the view it opens in may still be being made (then the bar shows).
        $transcript = $this->editableTranscript();
        $this->viewsMaking = $transcript !== null && app(TranscriptViews::class)->state($transcript)['status'] === 'making';
        $this->viewsBar = RecordingProgress::improvingBar($session);
        $this->pushPlayer();
        match ($session->finalTranscript?->status) {
            TranscriptStatus::Completed => Notification::make()->title('The transcript is ready')->success()->send(),
            TranscriptStatus::Failed => Notification::make()->title('The transcript could not be made')->body('See the error on this page, then try again.')->danger()->send(),
            default => null,
        };
    }

    /**
     * "Notify me when ready" (since v1.6, P5.4): one email when this recording's transcript is ready, or could not be
     * made, whatever the email settings say. The recording is this person's (the page only opens their own).
     */
    public function notifyWhenReady(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }
        $key = 'notify-ready:'.$user->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            Notification::make()->title('Too many requests. Try again in a minute.')->warning()->send();

            return;
        }
        RateLimiter::hit($key, 60);
        if (! CaptureSessionsTable::isPending($this->session())) {
            Notification::make()->title('It is ready already')->success()->send();

            return;
        }
        if (app(ReadyNotices::class)->ask($user, $this->session())) {
            Notification::make()->title('You will get an email when it is ready')->success()->send();
        }
    }

    private function notifyAsked(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ReadyNotices::class)->asked($user, $this->session());
    }

    private function liveMessage(): string
    {
        $session = $this->session();
        if ($session->status === SessionStatus::Uploading) {
            return 'Uploading. This page updates by itself.';
        }
        // Since v1.5 an Urdu or Auto recording waits for its engine when that one is busy or down (EngineWait).
        $final = $session->finalTranscript()->first();
        $retryAt = $final !== null ? EngineWait::fields($final)['retry_at'] : null;
        if ($final !== null && $retryAt !== null) {
            $zone = app(SettingsRepository::class)->displayTimezone();
            $at = Carbon::parse($retryAt)->setTimezone($zone);

            return 'Waiting for '.($final->providerKey()?->getLabel() ?? $final->provider).' — retrying at '
                .$at->format($at->isSameDay(now()->setTimezone($zone)) ? 'g:i A' : 'M j, g:i A').'. This page updates by itself.';
        }

        return 'Transcribing. This page updates by itself when the transcript is ready.';
    }

    /**
     * Saves one edited line from the transcript player (inline editing). Returns the player's new state, or the
     * reason the edit was refused.
     *
     * @return array<string, mixed>
     */
    public function saveSegment(int $index, string $text): array
    {
        $this->assertCanChange();
        $final = $this->editableTranscript();
        if ($final === null) {
            return ['error' => 'Only a finished transcript can be edited.'];
        }
        $before = [$index => (string) (($final->segments ?? [])[$index]['text'] ?? '')];
        try {
            app(TranscriptEditor::class)->edit($final, [$index => $text], $this->author());
        } catch (ApiError $e) {
            return ['error' => $e->getMessage()];
        }
        // For a client's recording, the words changed are offered as a rule for that client (as the apps do).
        $client = $this->session()->client;
        $learn = $client === null ? [] : array_map(
            fn (array $rule): array => $rule + ['client_name' => $client->name],
            TranscriptEditor::learnable($before, [$index => TranscriptEditor::cleanText($text)], TextReplacements::rules($client->replacements)),
        );

        return TranscriptPlayerEntry::payload($this->session()->refresh()) + ['learn' => $learn];
    }

    /**
     * "Always write X as Y for {client}?" → Yes: the rule goes first in the client's corrections, like
     * POST /api/vocabulary/replacements with a client.
     *
     * @return array{ok?: bool, error?: string}
     */
    public function learnRule(string $from, string $to): array
    {
        $this->assertCanChange();
        $client = $this->session()->client;
        if ($client === null) {
            return ['error' => 'File this recording under a project first.'];
        }
        $rule = TextReplacements::rules([['from' => $from, 'to' => $to, 'match_case' => false, 'whole_word' => true]]);
        if ($rule === []) {
            return ['error' => 'Type the words to replace and what to write instead.'];
        }
        $client->update(['replacements' => TextReplacements::rules([...$rule, ...TextReplacements::rules($client->replacements)])]);
        // The project's corrections are in its settings.
        Notification::make()->title('“'.$rule[0]['from'].'” is now written “'.$rule[0]['to'].'” for '.$client->name)
            ->url(ClientResource::getUrl('edit', ['record' => $client]))->success()->send();

        return ['ok' => true];
    }

    /**
     * Accept (the words change in that line, as an edit) or dismiss a suggested fix; returns the player's new state.
     *
     * @return array<string, mixed>
     */
    public function decideFix(int $suggestion, bool $accept): array
    {
        $this->assertCanChange();
        $final = $this->editableTranscript();
        if ($final === null) {
            return ['error' => 'Only a finished transcript can be changed.'];
        }
        try {
            app(ClientWorkspace::class)->decideFix($final, $suggestion, $accept, $this->author());
        } catch (ApiError $e) {
            return ['error' => $e->getMessage()];
        }

        return TranscriptPlayerEntry::payload($this->session()->refresh());
    }

    /**
     * Show as: makes the views (on the queue) when one is chosen that is not made yet; the page then polls until it is.
     *
     * @return array<string, mixed>|null the views' new state for the player
     */
    public function makeViews(): ?array
    {
        $this->assertCanChange();
        $transcript = $this->editableTranscript();
        if ($transcript === null) {
            return null;
        }
        try {
            $state = app(TranscriptViews::class)->request($transcript);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();
            $state = app(TranscriptViews::class)->state($transcript);
        }
        $this->viewsMaking = $state['status'] === 'making';

        return $state;
    }

    /** The poll while views are being made or call notes written: hands the player the views once they are ready. */
    public function refreshWork(): void
    {
        $transcript = $this->editableTranscript()?->refresh();
        if ($this->viewsMaking && ($transcript === null || app(TranscriptViews::class)->state($transcript)['status'] !== 'making')) {
            $this->viewsMaking = false;
            if ($this->viewsBar !== null) {
                $view = $transcript !== null ? app(TranscriptViews::class)->state($transcript) : null;
                $this->viewsBar = $view !== null && $view['status'] === 'ready'
                    ? ['label' => (TranscriptViews::VIEWS[$view['default']] ?? 'The view').' is ready', 'percent' => 100] : null;
            }
            $this->pushPlayer();
        } elseif ($this->viewsMaking) {
            $this->viewsBar = RecordingProgress::improvingBar($this->session()->refresh()) ?? $this->viewsBar;
        }
    }

    /** "Write notes" in English or Roman Urdu: like POST /api/sessions/{id}/notes, done on the queue. */
    public function writeNotes(string $language = 'en'): void
    {
        $this->assertCanChange();
        $session = $this->session();
        $project = app(ClientWorkspace::class)->projectOf($session);
        if ($project === null) {
            Notification::make()->title('File this recording under a project first.')->warning()->send();

            return;
        }
        try {
            app(ClientWorkspace::class)->assertCanWriteNotes($session->workspace_id);
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();

            return;
        }
        CallNote::query()->updateOrCreate(['capture_session_id' => $session->id], [
            'workspace_id' => $session->workspace_id,
            'client_id' => $project->id,
            'status' => 'pending',
            'error' => null,
            'language' => in_array($language, ['en', 'roman_urdu'], true) ? $language : 'en',
        ]);
        MakeCallNotesJob::dispatch($session->id);
    }

    public function callNote(): ?CallNote
    {
        $note = CallNote::query()->where('capture_session_id', $this->session()->id)->first();

        return $note instanceof CallNote ? $note : null;
    }

    /** Something on the page is still being made: the call notes, or the Show as views. */
    public function hasWorkInProgress(): bool
    {
        return $this->viewsMaking || $this->callNote()?->status === 'pending';
    }

    public function togglePin(): void
    {
        $this->assertCanChange();
        $session = app(LibraryOrganiser::class)->update($this->session(), ['pinned' => ! $this->session()->pinned]);
        Notification::make()->title($session->pinned ? 'Pinned to the top of the Library' : 'Unpinned')
            ->url($session->pinned ? CaptureSessionResource::getUrl('index', ['tab' => 'pinned']) : null)->success()->send();
    }

    public function toggleKeep(): void
    {
        $this->assertCanChange();
        $session = app(LibraryOrganiser::class)->update($this->session(), ['keep' => ! $this->session()->keep_forever]);
        Notification::make()->title($session->keep_forever ? 'Kept: never deleted automatically' : 'Deleted after the usual time again')->success()->send();
    }

    /** The Project chip: file the recording under a project (or none), as the apps' Organise does. */
    public function fileUnderAction(): Action
    {
        return Action::make('fileUnder')
            ->label('Project')
            ->modalHeading('File under a project')
            ->modalWidth('md')
            ->fillForm(fn (): array => ['client_id' => $this->session()->client_id])
            ->schema([
                Select::make('client_id')->label('Project')->placeholder('No project')
                    ->options(fn (): array => Client::query()->where('workspace_id', $this->session()->workspace_id)->where('user_id', $this->session()->user_id)->orderByDesc('pinned')->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->modalSubmitActionLabel('Save')
            ->action(function (array $data): void {
                $session = app(LibraryOrganiser::class)->update($this->session(), ['client_id' => $data['client_id'] ?? null]);
                Notification::make()->title($session->client !== null ? 'Filed under '.$session->client->name : 'No project')
                    ->url($session->client !== null ? ClientResource::getUrl('view', ['record' => $session->client]) : CaptureSessionResource::getUrl('index', ['folder' => 'inbox']))->success()->send();
            });
    }

    /** The Labels chip: the recording's labels, like the apps' Organise. */
    public function labelsAction(): Action
    {
        return Action::make('labels')
            ->label('Labels')
            ->modalHeading('Labels')
            ->modalWidth('md')
            ->fillForm(fn (): array => ['labels' => $this->session()->labelList()])
            ->schema([
                TagsInput::make('labels')->label('Labels')->placeholder('Add a label')
                    ->suggestions(fn (): array => array_column(app(LibraryOrganiser::class)->labels($this->session()->workspace_id), 'label'))
                    ->helperText('Press Enter after each label.'),
            ])
            ->modalSubmitActionLabel('Save')
            ->action(function (array $data): void {
                try {
                    app(LibraryOrganiser::class)->update($this->session(), ['labels' => array_values((array) ($data['labels'] ?? []))]);
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title('Labels saved')->success()->send();
            });
    }

    /**
     * Since v1.5 members and viewers have a read-only console: they open and play their recordings here, every change
     * is made in the apps. No action mounts for them, and the page's own changes refuse.
     */
    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        if (ConsoleAccess::readOnly()) {
            return null;
        }

        return parent::mountAction($name, $arguments, $context);
    }

    private function assertCanChange(): void
    {
        abort_if(ConsoleAccess::readOnly(), 403);
    }

    protected function getHeaderActions(): array
    {
        if (ConsoleAccess::readOnly()) {
            return [];
        }

        return [
            ActionGroup::make([
                $this->speakersAction(),
                $this->replaceAction(),
                $this->historyAction(),
            ])->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->button()->labeledFrom('sm')->color('gray')
                ->visible(fn (): bool => $this->editableTranscript() !== null),
            $this->downloadAction(),
            $this->wrongScriptAction(),
            $this->showAsAction(),
            $this->shareAction(),
            // Since v1.6 (P5.5): next to Share, as on the desktop.
            WhatsAppActions::send(fn (): CaptureSession => $this->session(), fn (): string => $this->readingView, copyForAi: true)
                ->label('WhatsApp')->labeledFrom('sm')->button(),
            ActionGroup::make([
                CaptureSessionsTable::renameAction(),
                SharingActions::send(fn (): CaptureSession => $this->session()),
                SharingActions::sentTo(fn (): CaptureSession => $this->session()),
                $this->retryAction(),
                $this->retranscribeAsAction(),
                $this->secondEngineAction(),
                $this->compareAction(),
                $this->compareEnginesAction(),
                $this->engineResultsAction(),
                $this->referenceAction(),
                $this->clearReferenceAction(),
                DeleteAction::make()->modalDescription('Deletes the recording and transcript everywhere (all devices). This cannot be undone.'),
            ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
        ];
    }

    /** Download: the audio, and the transcript as a file in the chosen Show as view (unsure words keep their marks). */
    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download')->labeledFrom('sm')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->button()
            ->color('gray')
            ->visible(fn (): bool => $this->editableTranscript() !== null || $this->session()->primaryRecording?->isReady() === true)
            ->modalHeading('Download')
            ->modalWidth('lg')
            ->modalContent(fn (): View => view('filament.library.download-panel', $this->downloadOptions()))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /** @return array{audio: array{url: string, label: string}|null, links: array<string, list<array{label: string, url: string, print: bool}>>, pack: array<string, list<array{label: string, url: string, print: bool}>>, screens: int, views: array<string, string>, default: string, unsure: bool} */
    private function downloadOptions(): array
    {
        $recording = $this->session()->primaryRecording;
        $transcript = $this->editableTranscript();
        $views = ['original' => 'Original'] + array_intersect_key(TranscriptViews::VIEWS, array_flip($this->readyViews()));
        $links = [];
        $pack = [];
        foreach ($transcript !== null ? array_keys($views) : [] as $view) {
            foreach (self::DOWNLOAD_FORMATS as $format => $label) {
                $query = array_filter(['view' => $view === 'original' ? null : $view, 'print' => $format === 'html' ? 1 : null]);
                $links[$view][] = ['label' => $label, 'print' => $format === 'html',
                    'url' => route('admin.transcripts.export', ['transcript' => $transcript->id, 'format' => $format, ...$query])];
            }
            // Meeting pack (v1.5): the transcript with its screens, a page to print or a ZIP (with the audio or without).
            $at = ['session' => $this->session()->id, 'view' => $view === 'original' ? null : $view];
            $pack[$view] = [
                ['label' => 'Meeting pack (print)', 'print' => true, 'url' => route('admin.sessions.pack', [...$at, 'format' => 'html'])],
                ['label' => 'Meeting pack (.zip)', 'print' => false, 'url' => route('admin.sessions.pack', [...$at, 'format' => 'zip'])],
            ];
            if ($recording?->isReady() === true) {
                $pack[$view][] = ['label' => 'Meeting pack with audio (.zip)', 'print' => false, 'url' => route('admin.sessions.pack', [...$at, 'format' => 'zip', 'include_audio' => 1])];
            }
        }
        // Opens in the view the player opens in (Settings → Transcription), when it is made.
        $default = $transcript !== null ? app(TranscriptViews::class)->state($transcript)['default'] : 'original';

        return [
            'audio' => $recording?->isReady() === true ? [
                'url' => route('admin.recordings.stream', ['recording' => $recording->id, 'download' => 1]),
                'label' => 'Audio (.'.AdminMediaController::extensionFor($recording->mime).')',
            ] : null,
            'links' => $links,
            'pack' => $pack,
            'screens' => $this->session()->screens()->count(),
            'views' => $views,
            'default' => array_key_exists($default, $views) ? $default : 'original',
            'unsure' => UnsureMarkers::settings()['style'] !== 'off',
        ];
    }

    /**
     * The Show as views that are made and fresh for the transcript on this page.
     *
     * @return list<string>
     */
    private function readyViews(): array
    {
        $transcript = $this->editableTranscript();
        if ($transcript === null) {
            return [];
        }
        $state = app(TranscriptViews::class)->state($transcript);

        return $state['status'] === 'ready' ? array_values(array_filter($state['available'], fn (string $v): bool => $state[$v] !== null)) : [];
    }

    /** "Show as…": makes the Roman Urdu and English views with Claude (on the queue); the player then offers them. */
    private function showAsAction(): Action
    {
        $visible = function (): bool {
            $transcript = $this->editableTranscript();

            return $transcript !== null && TranscriptViews::available($transcript) !== []
                && ! in_array(app(TranscriptViews::class)->state($transcript)['status'], ['ready'], true);
        };
        // Since v1.6 the views are a paid feature: without it the button carries the plan's badge and opens the sheet.
        if (! PlanLock::allows('views')) {
            return PlanLock::sheet('show_as', 'views', 'Show as…')->labeledFrom('sm')->visible($visible);
        }

        return Action::make('show_as')->label('Show as…')->labeledFrom('sm')->icon(Heroicon::OutlinedLanguage)->color('gray')
            ->visible($visible)
            ->action(function (): void {
                $transcript = $this->editableTranscript();
                if ($transcript === null) {
                    return;
                }
                try {
                    $state = app(TranscriptViews::class)->request($transcript);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title($state['status'] === 'ready' ? 'The views are ready' : 'Making the Show as views (Roman Urdu, English or Urdu). Reload the page in a minute or two.')->success()->send();
            });
    }

    private function speakersAction(): Action
    {
        return Action::make('speakers')
            ->label('Rename speakers')
            ->icon(Heroicon::OutlinedUserGroup)
            ->fillForm(function (): array {
                $names = app(TranscriptEditor::class)->speakerNames($this->session());

                return ['names' => array_map(fn (string $label): string => $names[$label] ?? '', $this->speakerLabels())];
            })
            ->schema(fn (): array => [
                ...array_map(fn (string $label, int $i): TextInput => TextInput::make("names.{$i}")
                    ->label("Name for “{$label}”")->placeholder($label)->maxLength(TranscriptEditor::MAX_SPEAKER_CHARS),
                    $this->speakerLabels(), array_keys($this->speakerLabels())),
                Toggle::make('remember')->label('Use these names for this project’s next recordings')
                    ->visible(fn (): bool => $this->session()->client !== null),
            ])
            ->action(function (array $data): void {
                $labels = $this->speakerLabels();
                $names = [];
                foreach ($labels as $i => $label) {
                    $names[$label] = $data['names'][$i] ?? null;
                }
                app(TranscriptEditor::class)->renameSpeakers($this->session(), $names, (bool) ($data['remember'] ?? false));
                $this->pushPlayer();
                Notification::make()->title('Speaker names saved')->success()->send();
            });
    }

    private function replaceAction(): Action
    {
        return Action::make('replace')
            ->label('Find and replace')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->schema([
                TextInput::make('find')->label('Find')->required()->maxLength(200),
                TextInput::make('replace')->label('Replace with')->maxLength(200),
                Checkbox::make('match_case')->label('Match case'),
                Checkbox::make('whole_word')->label('Whole words only')->default(true),
            ])
            ->action(function (array $data): void {
                $final = $this->editableTranscript();
                if ($final === null) {
                    return;
                }
                [, $count] = app(TranscriptEditor::class)->replace($final, (string) $data['find'], (string) ($data['replace'] ?? ''),
                    (bool) ($data['match_case'] ?? false), (bool) ($data['whole_word'] ?? true), $this->author());
                $this->pushPlayer();
                Notification::make()->title($count === 0 ? 'Nothing matched' : ($count === 1 ? 'Replaced 1 time' : "Replaced {$count} times"))
                    ->{$count === 0 ? 'warning' : 'success'}()->send();
            });
    }

    private function historyAction(): Action
    {
        return Action::make('history')
            ->label('History')
            ->icon(Heroicon::OutlinedClock)
            ->modalHeading('Transcript history')
            ->modalContent(fn (): View => view('filament.pages.transcript-history', [
                'revisions' => ($t = $this->editableTranscript()) ? app(TranscriptEditor::class)->revisions($t) : collect(),
            ]))
            ->modalSubmitActionLabel('Restore original')
            ->modalSubmitAction(fn (Action $action) => $action->color('danger')
                ->visible(fn (): bool => $this->editableTranscript()?->edited_at !== null))
            ->modalCancelActionLabel('Close')
            ->action(function (): void {
                $final = $this->editableTranscript();
                if ($final !== null) {
                    app(TranscriptEditor::class)->restoreOriginal($final, $this->author());
                    $this->pushPlayer();
                    Notification::make()->title('The original transcript is back')->success()->send();
                }
            });
    }

    /** Share (v1.5 dialog): anyone with the link, a password, or named people; downloads on or off; an expiry or none. */
    private function shareAction(): Action
    {
        return SharingActions::share(fn (): CaptureSession => $this->session(), fn (): array => $this->readyViews(),
            fn (): bool => $this->session()->primaryRecording?->isReady() === true)
            ->visible(fn (): bool => ! $this->session()->hasNoAudio());
    }

    /**
     * Shown when the final transcript came back in the wrong script for its language (Urdu as Devanagari, for
     * example): one click sends it to the next engine for that language as a second version to compare.
     */
    private function wrongScriptAction(): Action
    {
        return Action::make('wrong_script')
            ->label(function (CaptureSession $record): string {
                $next = $this->nextEngine($record);

                return 'Wrong script detected'.($next !== null ? ' · Transcribe with '.$next->getLabel() : '');
            })
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->visible(fn (CaptureSession $record): bool => is_string($record->finalTranscript?->meta['script_warning'] ?? null))
            ->requiresConfirmation()
            ->modalHeading('Wrong script detected')
            ->modalDescription(fn (CaptureSession $record): string => 'The transcript is mostly in '.ScriptCheck::label((string) ($record->finalTranscript?->meta['script_warning'] ?? ''))
                .', not in the script of '.LanguageChoice::label($record->finalTranscript?->language).'. Send the recording to '
                .($this->nextEngine($record)?->getLabel() ?? 'another engine').' and compare the two versions.')
            ->modalSubmitActionLabel('Transcribe again')
            ->action(function (CaptureSession $record): void {
                $next = $this->nextEngine($record);
                if ($next === null) {
                    Notification::make()->title('No other engine for this language has a key. Add one on API Credentials.')
                        ->url(ApiCredentials::canAccess() ? ApiCredentials::getUrl(panel: 'admin') : null)->warning()->send();

                    return;
                }
                try {
                    app(Retranscriber::class)->start($record, $next, $record->finalTranscript?->language);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title('Second version queued with '.$next->getLabel())->body('Open “Compare versions” when it is done.')->success()->send();
            });
    }

    /** The first engine for the transcript's language that is not the one that produced it. */
    private function nextEngine(CaptureSession $record): ?SttProviderKey
    {
        $final = $record->finalTranscript;
        foreach (app(LanguageRouter::class)->candidates($final?->language, $record) as $engine) {
            if ($engine->value !== $final?->provider) {
                return $engine;
            }
        }

        return null;
    }

    /** Compare engines: the same audio through up to three engines for one language (the bake-off). */
    private function compareEnginesAction(): Action
    {
        return Action::make('compare_engines')
            ->label('Compare engines')
            ->icon(Heroicon::OutlinedScale)
            ->visible(fn (CaptureSession $record): bool => $record->primaryRecording?->isReady() === true)
            ->modalHeading('Compare engines')
            ->modalDescription('The recording is transcribed again by each engine you pick, in the language you choose. Each run is billed by its engine. Nothing replaces the transcript until you choose.')
            ->fillForm(fn (CaptureSession $record): array => [
                'language' => app(LanguageRouter::class)->languageFor($record) ?? 'en+ur',
                'engines' => array_map(fn (SttProviderKey $k): string => $k->value, array_slice(app(LanguageRouter::class)->candidates(app(LanguageRouter::class)->languageFor($record) ?? 'en+ur', $record), 0, EngineComparison::MAX_ENGINES)),
            ])
            ->schema([
                Select::make('language')->label('Language')->options(array_filter(LanguageChoice::OFFERED, fn (string $k): bool => $k !== '', ARRAY_FILTER_USE_KEY))->required()->live(),
                CheckboxList::make('engines')->label('Engines (up to 3)')
                    ->options(fn (CaptureSession $record, $get): array => collect(app(LanguageRouter::class)->candidates((string) $get('language'), $record))
                        ->mapWithKeys(fn (SttProviderKey $k): array => [$k->value => $k->getLabel()])->all())
                    ->maxItems(EngineComparison::MAX_ENGINES)->minItems(1)->required()
                    ->helperText('Only engines with a key on API Credentials that support the language are listed.'),
            ])
            ->action(function (array $data, CaptureSession $record): void {
                try {
                    $runs = app(EngineComparison::class)->start($record, array_map(fn (string $v): SttProviderKey => SttProviderKey::from($v), (array) $data['engines']), (string) $data['language']);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title(count($runs).' engine run(s) queued')->body('Open “Engine results” when they are done.')->success()->send();
            });
    }

    /** The runs side by side, with cost; pick the winner for the language or use a run as the transcript. */
    /**
     * Accuracy Lab: the corrected transcript the engines are scored against (owner only). Since v1.5 it can be uploaded
     * (a TurboScribe or subtitle file), a Roman Urdu reference scores the Roman Urdu view on its own, and the recording
     * is put in a category (Urdu, English or mixed).
     */
    private function referenceAction(): Action
    {
        $file = fn (string $name): FileUpload => FileUpload::make($name)->label('…or upload it (.txt, .srt, .vtt or .docx)')->storeFiles(false)
            ->maxSize((int) (ReferenceText::MAX_BYTES / 1024));

        return Action::make('accuracy_reference')
            ->label(fn (): string => $this->session()->hasReference() ? 'Edit the Accuracy Lab reference' : 'Use as Accuracy Lab reference')
            ->icon(Heroicon::OutlinedBeaker)
            ->visible(fn (): bool => self::isOwner() && $this->editableTranscript() !== null)
            ->modalDescription('The spoken words exactly as said, one line per turn, without speaker names: correct the transcript below, or paste or upload a corrected text (TurboScribe\'s .txt, .srt, .vtt or .docx; time codes and speaker labels are removed). Add the Roman Urdu text too to score the Roman Urdu view on its own. Every "Compare engines" run of this recording is scored against it in the Accuracy Lab.')
            ->schema([
                // An Arabic (or other language) recording also offers its own language, and starts in it.
                Select::make('category')->label('Counts in')->options(fn (): array => app(AccuracyLab::class)->categoryOptions($this->session()))
                    ->required()->selectablePlaceholder(false)->default(fn (): string => app(AccuracyLab::class)->category($this->session())),
                Textarea::make('reference')->label('Reference transcript (as spoken)')->rows(12)
                    ->default(fn (): string => $this->session()->hasReference() ? (string) $this->session()->reference_text : AccuracyLab::spokenText($this->editableTranscript())),
                $file('reference_file'),
                Textarea::make('reference_roman')->label('Roman Urdu reference (optional)')->rows(6)
                    ->default(fn (): ?string => $this->session()->reference_roman),
                $file('reference_roman_file'),
            ])
            ->action(function (array $data): void {
                try {
                    app(AccuracyLab::class)->setReference(
                        $this->session(),
                        self::uploadedReference($data['reference_file'] ?? null) ?? (string) ($data['reference'] ?? ''),
                        self::uploadedReference($data['reference_roman_file'] ?? null) ?? ($data['reference_roman'] ?? null),
                        is_string($data['category'] ?? null) ? $data['category'] : null,
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title('Reference saved. Run "Compare engines" to score the engines on this recording.')->success()->send();
            });
    }

    /** The text of an uploaded reference file, or null when none was uploaded. Throws with a message safe to show. */
    private static function uploadedReference(mixed $file): ?string
    {
        $file = is_array($file) ? (array_values($file)[0] ?? null) : $file;
        if (! $file instanceof TemporaryUploadedFile) {
            return null;
        }
        $path = $file->getRealPath();
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('The file could not be read.');
        }

        return ReferenceText::fromFile($path, $file->getClientOriginalName());
    }

    private function clearReferenceAction(): Action
    {
        return Action::make('accuracy_reference_clear')->label('Remove the Accuracy Lab reference')->icon(Heroicon::OutlinedXMark)
            ->visible(fn (): bool => self::isOwner() && $this->session()->hasReference())
            ->requiresConfirmation()
            ->action(function (): void {
                app(AccuracyLab::class)->clearReference($this->session());
                Notification::make()->title('The recording no longer counts in the Accuracy Lab.')->success()->send();
            });
    }

    private static function isOwner(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin;
    }

    private function engineResultsAction(): Action
    {
        return Action::make('engine_results')
            ->label('Engine results')
            ->icon(Heroicon::OutlinedTableCells)
            ->visible(fn (CaptureSession $record): bool => $record->trials()->exists())
            ->slideOver()
            ->modalWidth('7xl')
            ->modalHeading('Engine results')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (CaptureSession $record): View => view('filament.pages.engine-results', ['runs' => app(EngineComparison::class)->results($record), 'canSetDefault' => self::isOwner()]));
    }

    /** "Make default for <language>" on a run in Engine results: the whole server's routing, so the operator's alone. */
    public function pickEngine(string $runId): void
    {
        abort_unless(self::isOwner(), 403);
        $this->assertCanChange();
        $run = $this->session()->trials()->find($runId);
        if (! $run instanceof Transcript) {
            return;
        }
        try {
            app(EngineComparison::class)->pick($run);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

            return;
        }
        // The order is Settings → Engine per language.
        Notification::make()->title($run->providerKey()?->getLabel().' is now the first engine for '.LanguageChoice::label($run->language))
            ->url(Settings::canAccess() ? Settings::getUrl(panel: 'admin') : null)->success()->send();
    }

    /** "Use this version" on a run in Engine results. */
    public function useEngineRun(string $runId): void
    {
        $this->assertCanChange();
        $run = $this->session()->trials()->find($runId);
        if (! $run instanceof Transcript) {
            return;
        }
        try {
            app(EngineComparison::class)->useAsTranscript($run);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

            return;
        }
        $this->pushPlayer();
        Notification::make()->title('That version is now the transcript')->body('The previous one is kept as the second version.')->success()->send();
    }

    private function retryAction(): Action
    {
        return Action::make('retry')
            ->label('Transcribe again')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (CaptureSession $record): bool => $record->primaryRecording?->isReady()
                && in_array($record->finalTranscript?->status, [null, TranscriptStatus::Failed], true))
            ->fillForm(fn (CaptureSession $record): array => ['language' => LanguageChoice::normalize($record->language) ?? ''])
            ->schema([
                Select::make('language')->label('Language')->options(LanguageChoice::OFFERED)->selectablePlaceholder(false),
            ])
            ->action(function (array $data, CaptureSession $record, FinalTranscriptCoordinator $coordinator): void {
                $record->update(['language' => LanguageChoice::normalize($data['language'] ?? null)]);
                $coordinator->start($record, $record->finalTranscript);
                Notification::make()->title('Transcription queued')->success()->send();
            });
    }

    /** "Re-transcribe as …": the engine for the new language runs; its version becomes the transcript when done. */
    private function retranscribeAsAction(): Action
    {
        return Action::make('retranscribe_as')
            ->label('Re-transcribe as…')
            ->icon(Heroicon::OutlinedLanguage)
            ->visible(fn (CaptureSession $record): bool => $record->primaryRecording?->isReady() === true && ! $record->hasNoAudio())
            ->modalDescription('The current transcript stays until the new one is ready, then the new one is shown and the old one is kept as the other version.')
            ->fillForm(fn (CaptureSession $record): array => ['language' => LanguageChoice::normalize($record->language) ?? ''])
            ->schema([
                Select::make('language')->label('Language')->options(LanguageChoice::selectOptions())->selectablePlaceholder(false)->live()
                    // English only on a recording with Urdu in it: the engine would translate the Urdu (said before it runs).
                    ->helperText(fn (?string $state, CaptureSession $record): ?string => $state === 'en' && ChannelLanguage::hasUrdu(
                        $record->finalTranscript?->language,
                        (string) ($record->finalTranscript?->text ?: implode(' ', array_column($record->finalTranscript->segments ?? [], 'text')))
                    )
                        ? 'This recording has Urdu in it. English only makes the engine translate the Urdu into English. Choose Urdu (with English words) to keep every word.'
                        : null),
            ])
            ->action(function (array $data, CaptureSession $record): void {
                try {
                    $run = app(Retranscriber::class)->startAs($record, $data['language'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title('Re-transcribing in '.LanguageChoice::label($run->language).' with '.($run->providerKey()?->getLabel() ?? $run->provider))->success()->send();
            });
    }

    /**
     * "Backup engine — Re-transcribe?" (since v1.5, the banner under the chips): a lower engine wrote the transcript of
     * an Urdu or Auto recording, so the first engine of its list tries again in the same language (Re-transcribe as).
     */
    public function retranscribeBackupAction(): Action
    {
        return Action::make('retranscribeBackup')
            ->label('Re-transcribe')
            ->requiresConfirmation()
            ->modalHeading('Re-transcribe with the first engine?')
            ->modalDescription('The first engine for this language transcribes the recording again, in the same language. The current transcript stays until the new one is ready.')
            ->modalSubmitActionLabel('Re-transcribe')
            ->visible(fn (): bool => $this->session()->primaryRecording?->isReady() === true && ! $this->session()->hasNoAudio())
            ->action(function (): void {
                $session = $this->session();
                try {
                    $run = app(Retranscriber::class)->startAs($session, LanguageChoice::normalize($session->language));
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title('Re-transcribing with '.($run->providerKey()?->getLabel() ?? $run->provider))->success()->send();
            });
    }

    private function secondEngineAction(): Action
    {
        return Action::make('second_engine')
            ->label('Transcribe with another engine')
            ->icon(Heroicon::OutlinedBeaker)
            ->visible(fn (CaptureSession $record): bool => $record->finalTranscript?->status === TranscriptStatus::Completed
                && $record->primaryRecording?->isReady() === true)
            ->modalDescription('Both versions are kept so you can compare them and choose the one to use. The engine bills its usual price.')
            ->fillForm(fn (CaptureSession $record): array => ['language' => LanguageChoice::normalize($record->language) ?? ''])
            ->schema(fn (CaptureSession $record): array => [
                Select::make('provider')->label('Engine')->required()->options(collect(SttProviderKey::cases())
                    ->reject(fn (SttProviderKey $k): bool => $k->value === $record->finalTranscript?->provider)
                    ->mapWithKeys(fn (SttProviderKey $k): array => [$k->value => $k->getLabel()])->all()),
                Select::make('language')->label('Language')->options(LanguageChoice::OFFERED)->selectablePlaceholder(false),
            ])
            ->action(function (array $data, CaptureSession $record): void {
                try {
                    app(Retranscriber::class)->start($record, SttProviderKey::from((string) $data['provider']), $data['language'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title('Second version queued')->body('Open “Compare versions” when it is done.')->success()->send();
            });
    }

    private function compareAction(): Action
    {
        return Action::make('compare')
            ->label('Compare versions')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (CaptureSession $record): bool => $this->alternate() !== null)
            ->slideOver()
            ->modalWidth('7xl')
            ->modalHeading('Compare versions')
            ->modalContent(fn (CaptureSession $record): View => view('filament.pages.transcript-compare', $this->comparison($record)))
            ->modalSubmitActionLabel('Use the second version')
            ->modalSubmitAction(fn (Action $action) => $action->visible(fn (): bool => $this->alternate()?->status === TranscriptStatus::Completed))
            ->extraModalFooterActions([
                Action::make('discard_second')->label('Remove the second version')->color('danger')->requiresConfirmation()
                    ->cancelParentActions()
                    ->action(function (): void {
                        if (($alternate = $this->alternate()) !== null) {
                            app(Retranscriber::class)->discard($alternate);
                            Notification::make()->title('Second version removed')->success()->send();
                        }
                    }),
            ])
            ->action(function (): void {
                $alternate = $this->alternate();
                if ($alternate === null) {
                    return;
                }
                app(Retranscriber::class)->choose($alternate);
                $this->pushPlayer();
                Notification::make()->title('The second version is now the transcript')->success()->send();
            });
    }

    /** @return array<string, mixed> */
    private function comparison(CaptureSession $record): array
    {
        $final = $record->finalTranscript;
        $alternate = $this->alternate();

        return [
            'final' => $final,
            'alternate' => $alternate,
            'diff' => $final !== null && $alternate !== null && $alternate->status === TranscriptStatus::Completed
                ? TranscriptDiff::compare($final->segments ?? [], $alternate->segments ?? []) : null,
        ];
    }

    private function alternate(): ?Transcript
    {
        $alternate = $this->session()->transcripts()->where('kind', TranscriptKind::Alternate->value)->first();

        return $alternate instanceof Transcript ? $alternate : null;
    }

    private function editableTranscript(): ?Transcript
    {
        $final = $this->session()->finalTranscript;

        return $final instanceof Transcript && $final->status === TranscriptStatus::Completed ? $final : null;
    }

    /** @return list<string> the speaker labels used in the transcript, in order of first appearance */
    private function speakerLabels(): array
    {
        $labels = [];
        foreach ($this->editableTranscript()->segments ?? [] as $segment) {
            $label = (string) ($segment['speaker'] ?? '');
            if ($label !== '' && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /** Sends the player its new state (it is not re-rendered by Livewire). */
    private function pushPlayer(): void
    {
        $this->dispatch('el-transcript-updated', ...TranscriptPlayerEntry::payload($this->session()->refresh()));
    }

    private function session(): CaptureSession
    {
        /** @var CaptureSession $record */
        $record = $this->getRecord();

        return $record;
    }

    private function author(): ?string
    {
        $user = auth()->user();

        return $user !== null ? mb_substr((string) $user->getAttribute('name'), 0, 120) : null;
    }

    /** "Sessions > Title", without a trailing "View" (C3). */
    public function getBreadcrumbs(): array
    {
        return $this->getResourceBreadcrumbs();
    }
}
