<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Models\AuditEvent;
use App\Services\AuditLog;
use App\Services\Staff\StaffAccess;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Admin panel → Activity log (since v1.6, P2.7): everything the audit log keeps, for the whole platform: sign-ins,
 * devices, sharing, settings, plans, staff and support actions, with who did it, from where and when. Filters by
 * what happened, who did it (a person, an app or the server), by person and by date. Summaries name things, never
 * what was said in a recording.
 */
class ActivityLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Activity log';

    protected static ?string $slug = 'activity-log';

    public static function canAccess(): bool
    {
        return StaffAccess::can('audit.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AuditEvent::query()->with('user:id,name,email'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, Y H:i')->sortable(),
                TextColumn::make('event')->label('What')->badge()->color('gray')->formatStateUsing(fn (string $state): string => AuditLog::EVENTS[$state] ?? $state),
                TextColumn::make('summary')->wrap()->searchable(),
                TextColumn::make('user.email')->label('Account')->placeholder('—')->searchable()
                    ->url(fn (AuditEvent $record): ?string => $record->user_id !== null && StaffAccess::can('customers.view') ? ViewCustomer::getUrl(['record' => $record->user_id]) : null),
                TextColumn::make('actor')->label('By')->formatStateUsing(fn (string $state): string => self::ACTORS[$state] ?? $state),
                TextColumn::make('ip')->label('From')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')->label('What')->multiple()->options(AuditLog::EVENTS),
                SelectFilter::make('actor')->label('By')->options(self::ACTORS),
                Filter::make('when')->schema([
                    DatePicker::make('from')->label('From')->native(),
                    DatePicker::make('to')->label('To')->native(),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse((string) $data['from'])->startOfDay()))
                    ->when(filled($data['to'] ?? null), fn (Builder $q) => $q->where('created_at', '<', Carbon::parse((string) $data['to'])->addDay()->startOfDay()))),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading('Nothing logged yet');
    }

    /** Who did it, as the log names them. */
    private const ACTORS = ['web' => 'A person in the app', 'device' => 'A phone or computer', 'connector' => 'A connected app', 'system' => 'The server'];
}
