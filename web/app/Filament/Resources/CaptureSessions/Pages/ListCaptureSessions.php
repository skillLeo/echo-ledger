<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\SttUseCase;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\FinalTranscriptCoordinator;
use App\Services\LibraryOrganiser;
use App\Services\RecordingIngestor;
use App\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListCaptureSessions extends ListRecords
{
    protected static string $resource = CaptureSessionResource::class;

    /** Smart views (B15), in the order the tabs show. */
    public const VIEW_LABELS = [
        'today' => 'Today',
        'this_week' => 'This week',
        'waiting' => 'Waiting for decision',
        'bookmarked' => 'Bookmarked',
        'failed' => 'Failed uploads',
        'pinned' => 'Pinned',
    ];

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $organiser = app(LibraryOrganiser::class);
        $counts = $organiser->counts((int) auth()->id());
        $tabs = ['all' => Tab::make('All')];
        foreach (self::VIEW_LABELS as $view => $label) {
            $tabs[$view] = Tab::make($label)
                ->badge($counts[$view] > 0 ? $counts[$view] : null)
                ->modifyQueryUsing(fn (Builder $query): Builder => $organiser->applyView($query, $view));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadVoiceNote')
                ->label('Upload voice note')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription('Voice notes are never auto-recorded. Upload one here (source: Web).')
                ->schema([
                    FileUpload::make('audio')
                        ->label('Audio file')
                        ->disk('local')
                        ->directory('tmp-uploads')
                        ->visibility('private')
                        ->acceptedFileTypes((array) config('echoledger.accepted_mimes'))
                        ->maxSize(12 * 1024)
                        ->required(),
                    TextInput::make('title')->maxLength(200),
                    Select::make('client_id')->label('Client')
                        ->options(fn (): array => Client::query()->where('user_id', auth()->id())->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ])
                ->action(function (array $data, RecordingIngestor $ingestor, FinalTranscriptCoordinator $coordinator, SettingsRepository $settings): void {
                    $disk = Storage::disk('local');
                    $relative = (string) $data['audio'];
                    $path = $disk->path($relative);
                    try {
                        $session = CaptureSession::query()->create([
                            'user_id' => auth()->id(),
                            'client_id' => $data['client_id'] ?? null,
                            'client_ref' => (string) Str::uuid(),
                            'source_device' => SourceDevice::Web,
                            'source_app' => SourceApp::VoiceNote,
                            'title' => $data['title'] ?? null,
                            'started_at' => now(),
                            'status' => SessionStatus::Processing,
                            'final_provider' => $settings->providerFor(SttUseCase::VoiceNote)->value,
                        ]);
                        $mime = (string) ($disk->mimeType($relative) ?: 'application/octet-stream');
                        $ingestor->ingestLocalFile($session, $path, RecordingRole::Primary, $mime);
                        $coordinator->maybeStart($session);
                    } finally {
                        $disk->delete($relative); // plaintext upload removed right after encryption
                    }

                    Notification::make()->title('Voice note uploaded')->body('Transcription runs on the next queue tick.')->success()->send();
                }),
        ];
    }
}
