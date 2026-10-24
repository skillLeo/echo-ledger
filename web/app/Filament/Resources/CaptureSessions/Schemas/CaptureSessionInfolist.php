<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Schemas;

use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
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
                    TextEntry::make('device.name')->label('Device')->placeholder('Web upload'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('finalTranscript.status')->label('Transcript')->badge()->placeholder('Not started'),
                    TextEntry::make('finalTranscript.provider')->label('Engine')->placeholder('—'),
                    TextEntry::make('finalTranscript.language')->label('Language')->placeholder('auto'),
                    TextEntry::make('finalTranscript.error')->label('Error')->color('danger')->placeholder('—')
                        ->visible(fn (CaptureSession $r): bool => filled($r->finalTranscript?->error))->columnSpan(2),
                ]),
            ]),
            TranscriptPlayerEntry::make('player')->hiddenLabel()->columnSpanFull(),
        ]);
    }
}
