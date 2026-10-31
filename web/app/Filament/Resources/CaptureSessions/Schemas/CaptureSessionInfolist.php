<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Schemas;

use App\Enums\SttProviderKey;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
use App\Stt\LanguageChoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The recording page, laid out like the phone's session screen: the Client, Language and Labels chips, then the
 * transcript with its docked player; beside it on wide screens (below it on phones) the call notes and the details.
 */
final class CaptureSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 3])->columnSpanFull()->schema([
                Group::make([
                    ViewEntry::make('organise')->hiddenLabel()->view('filament.recording.chips'),
                    TranscriptPlayerEntry::make('player')->hiddenLabel()->visible(fn (CaptureSession $r): bool => ! $r->hasNoAudio()),
                ])->columnSpan(['default' => 1, 'xl' => 2]),
                Group::make([
                    ViewEntry::make('call_notes')->hiddenLabel()->view('filament.recording.call-notes')
                        ->visible(fn (CaptureSession $r): bool => $r->client_id !== null && ! $r->hasNoAudio()),
                    self::details(),
                ])->columnSpan(1),
            ]),
        ]);
    }

    private static function details(): Section
    {
        return Section::make('Details')->compact()->extraAttributes(['class' => 'el-details-card'])->schema([
            Grid::make(['default' => 2])->schema([
                TextEntry::make('source_device')->label('Source')->badge(),
                TextEntry::make('source_app')->label('App')->badge(),
                TextEntry::make('started_at')->label('Started')->dateTime('M j, Y · g:i A')->columnSpan(2),
                TextEntry::make('duration_ms')->label('Duration')
                    ->state(fn (CaptureSession $r): string => CaptureSessionsTable::duration($r->duration_ms) ?: '—'),
                TextEntry::make('client.name')->label('Client')->placeholder('—'),
                // Caller names (since v1.2): who the recording was with, and where the device saw the name.
                TextEntry::make('caller_name')->label('With')->placeholder('—')->columnSpan(2)
                    ->formatStateUsing(fn (?string $state, CaptureSession $record): string => trim(($state ?? '').' '.($record->caller_number !== null ? '('.$record->caller_number.')' : '')))
                    ->helperText(fn (CaptureSession $record): ?string => match ($record->caller_source) {
                        'whatsapp_notification' => 'From the WhatsApp call notification',
                        'window_title' => 'From the meeting window',
                        'calendar' => 'From the calendar',
                        'contacts', 'phone' => "From the phone's contacts",
                        default => null,
                    }),
                TextEntry::make('device.name')->label('Device')->columnSpan(2)
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
            ]),
        ]);
    }
}
