<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
use App\Services\FinalTranscriptCoordinator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCaptureSession extends ViewRecord
{
    protected static string $resource = CaptureSessionResource::class;

    public function getTitle(): string
    {
        /** @var CaptureSession $record */
        $record = $this->getRecord();

        return $record->displayTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Re-transcribe')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (CaptureSession $record): bool => $record->primaryRecording?->isReady()
                    && in_array($record->finalTranscript?->status, [null, TranscriptStatus::Failed], true))
                ->requiresConfirmation()
                ->action(function (CaptureSession $record, FinalTranscriptCoordinator $coordinator): void {
                    $coordinator->start($record, $record->finalTranscript);
                    Notification::make()->title('Transcription queued')->success()->send();
                }),
            DeleteAction::make()->modalDescription('Deletes the recording and transcript everywhere (all devices). This cannot be undone.'),
        ];
    }
}
