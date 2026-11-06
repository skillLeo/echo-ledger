<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions;

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\CaptureSessions\RelationManagers\ShareLinksRelationManager;
use App\Filament\Resources\CaptureSessions\Schemas\CaptureSessionInfolist;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Resources\CaptureSessions\Widgets\WaitingDecisionsWidget;
use App\Models\CaptureSession;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The unified library: every capture from phone, desktop and web, labeled with icon + text. */
class CaptureSessionResource extends Resource
{
    protected static ?string $model = CaptureSession::class;

    protected static ?string $slug = 'sessions';

    protected static ?string $modelLabel = 'recording';

    protected static ?string $navigationLabel = 'Library';

    protected static ?string $breadcrumb = 'Library';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Film;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    /** Every console has the library: since v1.5 the small one (members and viewers) reads it only. */
    public static function canAccess(): bool
    {
        return ConsoleAccess::open();
    }

    public static function canCreate(): bool
    {
        return false; // sessions come from devices or Upload on the list
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('workspace_id', CurrentWorkspace::id())
            // Since v1.5 a person's recordings are theirs alone, owners and admins included.
            ->where('user_id', CurrentWorkspace::userId())
            ->with(['device', 'client', 'finalTranscript', 'primaryRecording']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CaptureSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CaptureSessionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ShareLinksRelationManager::class];
    }

    public static function getWidgets(): array
    {
        return [WaitingDecisionsWidget::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCaptureSessions::route('/'),
            'view' => ViewCaptureSession::route('/{record}'),
        ];
    }
}
