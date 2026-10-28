<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Schemas;

use App\Enums\SttProviderKey;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
use App\Stt\LanguageChoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CaptureSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Grid::make(['default' => 2, 'lg' => 6])->schema([
                    TextEntry::make('source_device')->label('Source')->badge(),
                    TextEntry::make('source_app')->label('App')->badge(),
                    TextEntry::make('started_at')->label('Started')->dateTime('M j, Y · g:i A'),
                    TextEntry::make('duration_ms')->label('Duration')
                        ->state(fn (CaptureSession $r): string => CaptureSessionsTable::duration($r->duration_ms) ?: '—'),
                    TextEntry::make('client.name')->label('Client')->placeholder('—'),
                    // Caller names (since v1.2): who the recording was with, and where the device saw the name.
                    TextEntry::make('caller_name')->label('With')->placeholder('—')
                        ->formatStateUsing(fn (?string $state, CaptureSession $record): string => trim(($state ?? '').' '.($record->caller_number !== null ? '('.$record->caller_number.')' : '')))
                        ->helperText(fn (CaptureSession $record): ?string => match ($record->caller_source) {
                            'whatsapp_notification' => 'From the WhatsApp call notification',
                            'window_title' => 'From the meeting window',
                            'calendar' => 'From the calendar',
                            'contacts', 'phone' => "From the phone's contacts",
                            default => null,
                        }),
                    TextEntry::make('device.name')->label('Device')
                        ->state(fn (CaptureSession $r): string => CaptureSessionsTable::deviceLabel($r)),
                    TextEntry::make('status')->badge()->visible(fn (CaptureSession $r): bool => ! $r->hasNoAudio()),
                    TextEntry::make('no_audio')->label('Status')->badge()->color('gray')->icon('heroicon-o-speaker-x-mark')
                        ->state('No audio')->visible(fn (CaptureSession $r): bool => $r->hasNoAudio()),
                    TextEntry::make('finalTranscript.status')->label('Transcript')->badge()->placeholder('Not started')
                        ->visible(fn (CaptureSession $r): bool => ! $r->hasNoAudio()),
                    TextEntry::make('finalTranscript.provider')->label('Transcribed by')->placeholder('—')
                        ->formatStateUsing(fn (?string $state): string => SttProviderKey::tryFrom((string) $state)?->getLabel() ?? (string) $state),
                    TextEntry::make('finalTranscript.language')->label('Language')->placeholder('Automatic')
                        ->formatStateUsing(fn (?string $state): string => LanguageChoice::OFFERED[(string) $state] ?? (string) $state),
                    TextEntry::make('finalTranscript.error')->label('Error')->color('danger')->placeholder('—')
                        ->visible(fn (CaptureSession $r): bool => filled($r->finalTranscript?->error) && ! $r->hasNoAudio())->columnSpan(2),
                    TextEntry::make('no_audio_help')->label('Why')->columnSpan(3)
                        ->state('Nothing was heard in this recording, so there is nothing to play or transcribe. On the phone run Setup → Call capture → Test call capture; then delete this recording.')
                        ->visible(fn (CaptureSession $r): bool => $r->hasNoAudio()),
                ]),
            ]),
            TranscriptPlayerEntry::make('player')->hiddenLabel()->columnSpanFull()->visible(fn (CaptureSession $r): bool => ! $r->hasNoAudio()),
        ]);
    }
}
