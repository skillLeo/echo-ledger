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
use App\Services\FinalTranscriptCoordinator;
use App\Services\Retranscriber;
use App\Services\ShareLinks;
use App\Services\TranscriptDiff;
use App\Services\TranscriptEditor;
use App\Stt\LanguageChoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
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
            ])->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray')
                ->visible(fn (): bool => $this->editableTranscript() !== null),
            $this->shareAction(),
            ActionGroup::make([
                $this->retryAction(),
                $this->secondEngineAction(),
                $this->compareAction(),
                DeleteAction::make()->modalDescription('Deletes the recording and transcript everywhere (all devices). This cannot be undone.'),
            ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
        ];
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
}
