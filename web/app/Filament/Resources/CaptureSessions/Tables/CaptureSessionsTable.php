<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Tables;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Models\CaptureSession;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CaptureSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')->label('When')->dateTime('M j, Y · H:i')->sortable()
                    ->description(fn (CaptureSession $r): string => self::duration($r->duration_ms)),
                TextColumn::make('source_device')->label('Source')->badge(),
                TextColumn::make('source_app')->label('App')->badge(),
                TextColumn::make('title')->label('Title')
                    ->state(fn (CaptureSession $r): string => $r->displayTitle())->limit(48)->searchable(['title']),
                TextColumn::make('client.name')->label('Client')->placeholder('—')->searchable(),
                TextColumn::make('device.name')->label('Device')->placeholder('Web upload')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('finalTranscript.status')->label('Transcript')->badge()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('source_device')->label('Source device')->options(SourceDevice::class)->multiple(),
                SelectFilter::make('source_app')->label('Source app')->options(SourceApp::class)->multiple(),
                SelectFilter::make('client')->relationship('client', 'name')->searchable()->preload(),
                SelectFilter::make('status')->options(SessionStatus::class),
                Filter::make('started_at')
                    ->schema([DatePicker::make('from')->label('From'), DatePicker::make('until')->label('Until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('started_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('started_at', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()->modalDescription('Deletes the recording and transcript everywhere (all devices). This cannot be undone.'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('No sessions yet')
            ->emptyStateDescription('Pair a phone or desktop under Devices, or upload a voice note.');
    }

    public static function duration(?int $ms): string
    {
        if ($ms === null) {
            return '';
        }
        $seconds = intdiv($ms, 1000);

        return $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds);
    }
}
