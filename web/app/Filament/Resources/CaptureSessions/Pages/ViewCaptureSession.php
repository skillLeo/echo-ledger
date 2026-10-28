<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\EngineComparison;
use App\Services\FinalTranscriptCoordinator;
use App\Services\Retranscriber;
use App\Services\ShareLinks;
use App\Services\TranscriptDiff;
use App\Services\TranscriptEditor;
use App\Services\Views\TranscriptViews;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use App\Stt\ScriptCheck;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use RuntimeException;

class ViewCaptureSession extends ViewRecord
{
    protected static string $resource = CaptureSessionResource::class;

    public function getTitle(): string
    {
        return $this->session()->displayTitle();
    }

    /**
     * Saves one edited line from the transcript player (inline editing). Returns the player's new state, or the
     * reason the edit was refused.
     *
     * @return array<string, mixed>
     */
    public function saveSegment(int $index, string $text): array
    {
        $final = $this->editableTranscript();
        if ($final === null) {
            return ['error' => 'Only a finished transcript can be edited.'];
        }
        try {
            app(TranscriptEditor::class)->edit($final, [$index => $text], $this->author());
        } catch (ApiError $e) {
            return ['error' => $e->getMessage()];
        }

        return TranscriptPlayerEntry::payload($this->session()->refresh());
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->speakersAction(),
                $this->replaceAction(),
                $this->historyAction(),
            ])->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->button()->color('gray')
                ->visible(fn (): bool => $this->editableTranscript() !== null),
            ActionGroup::make([
                ...array_map(fn (array $f): Action => Action::make('export_'.$f[0])->label($f[1])
                    ->url(fn (): string => route('admin.transcripts.export', ['transcript' => $this->editableTranscript()->id ?? '0', 'format' => $f[0]]))
                    ->openUrlInNewTab($f[0] === 'html'), [
                        ['docx', 'Word (.docx)'], ['html', 'Print or save as PDF'], ['txt', 'Text (.txt)'], ['md', 'Markdown (.md)'],
                        ['srt', 'Subtitles (.srt)'], ['vtt', 'Web subtitles (.vtt)'], ['json', 'JSON'],
                    ]),
                // Show as: the same files in Roman Urdu or English, once those views are made.
                ...array_map(fn (array $f): Action => Action::make('export_'.$f[0].'_'.$f[2])->label($f[1])
                    ->url(fn (): string => route('admin.transcripts.export', ['transcript' => $this->editableTranscript()->id ?? '0', 'format' => $f[0], 'view' => $f[2]]))
                    ->openUrlInNewTab($f[0] === 'html')
                    ->visible(fn (): bool => in_array($f[2], $this->readyViews(), true)), [
                        ['docx', 'Word (.docx) in Roman Urdu', 'roman'], ['docx', 'Word (.docx) in English', 'english'],
                        ['html', 'Print in Roman Urdu', 'roman'], ['html', 'Print in English', 'english'],
                        ['txt', 'Text (.txt) in Roman Urdu', 'roman'], ['txt', 'Text (.txt) in English', 'english'],
                    ]),
            ])->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray')
                ->visible(fn (): bool => $this->editableTranscript() !== null),
            $this->wrongScriptAction(),
            $this->showAsAction(),
            $this->shareAction(),
            ActionGroup::make([
                $this->retryAction(),
                $this->retranscribeAsAction(),
                $this->secondEngineAction(),
                $this->compareAction(),
                $this->compareEnginesAction(),
                $this->engineResultsAction(),
                DeleteAction::make()->modalDescription('Deletes the recording and transcript everywhere (all devices). This cannot be undone.'),
            ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
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
        return Action::make('show_as')->label('Roman Urdu & English')->icon(Heroicon::OutlinedLanguage)->color('gray')
            ->visible(function (): bool {
                $transcript = $this->editableTranscript();

                return $transcript !== null && TranscriptViews::available($transcript) !== []
                    && ! in_array(app(TranscriptViews::class)->state($transcript)['status'], ['ready'], true);
            })
            ->action(function (): void {
                $transcript = $this->editableTranscript();
                if ($transcript === null) {
                    return;
                }
                try {
                    $state = app(TranscriptViews::class)->request($transcript);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title($state['status'] === 'ready' ? 'The views are ready' : 'Making the Roman Urdu and English views. Reload the page in a minute or two.')->success()->send();
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
                Toggle::make('remember')->label('Use these names for this client’s next recordings')
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

    private function shareAction(): Action
    {
        return Action::make('share')
            ->label('Share')
            ->visible(fn (): bool => ! $this->session()->hasNoAudio())
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->button()
            ->modalDescription('A view-only link. Anyone with the link can read the transcript until it expires or you revoke it.')
            ->schema([
                Select::make('expires_in')->label('Expires after')->required()->default(86_400)
                    ->options([3_600 => '1 hour', 86_400 => '1 day', 604_800 => '7 days']),
                TextInput::make('password')->label('Password (optional)')->password()->revealable()->minLength(6)->maxLength(100),
                Toggle::make('include_audio')->label('Include the recording')
                    ->visible(fn (): bool => $this->session()->primaryRecording?->isReady() === true),
            ])
            ->action(function (array $data): void {
                try {
                    ['url' => $url] = app(ShareLinks::class)->create($this->session(), (int) $data['expires_in'],
                        filled($data['password'] ?? null) ? (string) $data['password'] : null, (bool) ($data['include_audio'] ?? false), $this->author());
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Share link ready. Copy it now: it is shown only once.')->body($url)->persistent()->success()->send();
            });
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
                    Notification::make()->title('No other engine for this language has a key. Add one on API Credentials.')->warning()->send();

                    return;
                }
                try {
                    app(Retranscriber::class)->start($record, $next, $record->finalTranscript?->language);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

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
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title(count($runs).' engine run(s) queued')->body('Open “Engine results” when they are done.')->success()->send();
            });
    }

    /** The runs side by side, with cost; pick the winner for the language or use a run as the transcript. */
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
            ->modalContent(fn (CaptureSession $record): View => view('filament.pages.engine-results', ['runs' => app(EngineComparison::class)->results($record)]));
    }

    /** "Make default for <language>" on a run in Engine results. */
    public function pickEngine(string $runId): void
    {
        $run = $this->session()->trials()->find($runId);
        if (! $run instanceof Transcript) {
            return;
        }
        try {
            app(EngineComparison::class)->pick($run);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($run->providerKey()?->getLabel().' is now the first engine for '.LanguageChoice::label($run->language))->success()->send();
    }

    /** "Use this version" on a run in Engine results. */
    public function useEngineRun(string $runId): void
    {
        $run = $this->session()->trials()->find($runId);
        if (! $run instanceof Transcript) {
            return;
        }
        try {
            app(EngineComparison::class)->useAsTranscript($run);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

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
                Select::make('language')->label('Language')->options(LanguageChoice::selectOptions())->selectablePlaceholder(false),
            ])
            ->action(function (array $data, CaptureSession $record): void {
                try {
                    $run = app(Retranscriber::class)->startAs($record, $data['language'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Re-transcribing in '.LanguageChoice::label($run->language).' with '.($run->providerKey()?->getLabel() ?? $run->provider))->success()->send();
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
                    Notification::make()->title($e->getMessage())->danger()->send();

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
