<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Services\RetentionPolicy;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** The 48-hour undo bin (plan B17): restore a deleted recording, or erase it now. */
class RecentlyDeleted extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Recently deleted';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CaptureSession::onlyTrashed()->where('workspace_id', CurrentWorkspace::id())->whereNull('media_erased_at')
                ->where('deleted_at', '>', now()->subHours(CaptureSession::BIN_HOURS)))
            ->defaultSort('deleted_at', 'desc')
            ->description('Deleted recordings stay here for '.CaptureSession::BIN_HOURS.' hours, then their audio and transcripts are erased for good.')
            ->columns([
                TextColumn::make('title')->label('Title')->state(fn (CaptureSession $r): string => $r->displayTitle())->limit(48),
                TextColumn::make('client.name')->label('Client')->placeholder('—'),
                TextColumn::make('started_at')->label('Recorded')->dateTime('M j, Y · g:i A'),
                TextColumn::make('deleted_at')->label('Deleted')->since(),
                TextColumn::make('erase_at')->label('Erased')
                    ->state(fn (CaptureSession $r): string => $r->deleted_at?->copy()->addHours(CaptureSession::BIN_HOURS)->diffForHumans() ?? ''),
            ])
            ->recordActions([
                Action::make('restore')->label('Restore')->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->authorize(fn (CaptureSession $record): bool => Gate::allows('restore', $record))
                    ->action(function (CaptureSession $record): void {
                        try {
                            app(RetentionPolicy::class)->restore($record);
                            Notification::make()->title('Recording restored')->success()->send();
                        } catch (ApiError $e) {
                            Notification::make()->title($e->getMessage())->warning()->send();
                        }
                    }),
                Action::make('eraseNow')->label('Delete now')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->authorize(fn (CaptureSession $record): bool => Gate::allows('forceDelete', $record))
                    ->requiresConfirmation()->modalDescription('The audio and transcript are erased now. This cannot be undone.')
                    ->action(function (CaptureSession $record): void {
                        app(RetentionPolicy::class)->deleteNow($record);
                        Notification::make()->title('Recording erased')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Nothing deleted in the last 48 hours');
    }
}
