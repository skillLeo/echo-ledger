<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\ApiError;
use App\Filament\Concerns\FullConsoleOnly;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Support\ConsoleDates;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\ObjectMenus;
use App\Models\CaptureSession;
use App\Services\RetentionPolicy;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/** The 48-hour undo bin (plan B17): restore a deleted recording, or erase it now. */
class RecentlyDeleted extends Page implements HasTable
{
    use FullConsoleOnly;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Trash;

    protected static string|UnitEnum|null $navigationGroup = 'More';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Recently deleted';

    /** In the console's language (the same English words in classic). */
    public function getTitle(): string|Htmlable
    {
        return __('Recently deleted');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make(),
            ...(ConsoleV2::any() ? [View::make('filament.v2.object-menus')->viewData(fn (): array => ['menus' => $this->objectMenus()])] : [])]);
    }

    /** UX-22 3b: Empty trash… on the page too (Trash's menu in the panel has it), asking first. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('emptyTrash')->label(__('Empty trash…'))->color('danger')->icon(Heroicon::OutlinedTrash)->requiresConfirmation()
                ->modalHeading(__('Empty trash?'))->modalDescription(__('Empty the trash? Its recordings can\'t be brought back.'))
                ->modalSubmitActionLabel(__('Empty trash'))
                ->visible(fn (): bool => ConsoleV2::any() && ! ConsoleAccess::readOnly() && app(RetentionPolicy::class)->bin((int) CurrentWorkspace::id())->isNotEmpty())
                ->action(function (): void {
                    $retention = app(RetentionPolicy::class);
                    $bin = $retention->bin((int) CurrentWorkspace::id());
                    foreach ($bin as $session) {
                        $retention->deleteNow($session);
                    }
                    Notification::make()->title(trans_choice(':count recording erased|:count recordings erased', $bin->count(), ['count' => $bin->count()]))->success()->send();
                }),
        ];
    }

    /** UX-22 3b: a recording in Trash: Restore | Delete immediately…. */
    public const OBJECT_ACTIONS = ['trashItem' => ['trash.restore', 'recording.deleteNow']];

    /** @return array<string, mixed> */
    public function objectMenus(): array
    {
        return ObjectMenus::data(self::OBJECT_ACTIONS);
    }

    /** A pick from a row's menu (filament.v2.context-menu-script): the row's own Restore or Delete now (asks first). */
    public function objectAction(string $kind, string $action, string $id, ?string $value = null): void
    {
        if (! ObjectMenus::picked(self::OBJECT_ACTIONS, $kind, $action)) {
            return;
        }
        $record = $this->getTable()->getQuery()->whereKey($id)->first();
        if (! $record instanceof CaptureSession) {
            return;
        }
        $this->mountAction($action === 'trash.restore' ? 'restore' : 'eraseNow', [], ['table' => true, 'recordKey' => (string) $record->getKey()]);
        if ($action === 'trash.restore') {
            $this->callMountedAction();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CaptureSession::onlyTrashed()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->whereNull('media_erased_at')
                ->where('deleted_at', '>', now()->subHours(CaptureSession::binHours())))
            ->defaultSort('deleted_at', 'desc')
            ->description(__('Deleted recordings stay here for :hours hours, then their audio and transcripts are erased for good.', ['hours' => CaptureSession::binHours()]))
            ->columns([
                TextColumn::make('title')->label(__('Title'))->state(fn (CaptureSession $r): string => $r->displayTitle())->limit(48)
                    // UX-22 3b: the row's right-click menu (Restore | Delete immediately…), under the new design.
                    ->extraAttributes(fn (CaptureSession $r): array => ConsoleV2::any() ? ['data-v2-object' => 'trashItem', 'data-v2-object-id' => (string) $r->getKey()] : []),
                TextColumn::make('client.name')->label(__('Project'))->placeholder('—'),
                // The day before the month in Urdu (ConsoleDates); in English as Filament writes it ("Nov 17, 2026 · 9:00 AM").
                TextColumn::make('started_at')->label(__('library_context.col_recorded'))->dateTime('M j, Y · g:i A')
                    ->formatStateUsing(fn (TextColumn $column, mixed $state): ?string => blank($state) ? null
                        : ConsoleDates::dateTime(Carbon::parse($state)->setTimezone($column->getTimezone()), 'g:i A')),
                TextColumn::make('deleted_at')->label(__('library_context.col_deleted'))->since(),
                TextColumn::make('erase_at')->label(__('library_context.col_erased'))
                    ->state(fn (CaptureSession $r): string => $r->deleted_at?->copy()->addHours(CaptureSession::binHours())->diffForHumans() ?? ''),
            ])
            ->recordActions([
                Action::make('restore')->label(__('Restore'))->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->authorize(fn (CaptureSession $record): bool => Gate::allows('restore', $record))
                    ->action(function (CaptureSession $record): void {
                        try {
                            app(RetentionPolicy::class)->restore($record);
                            Notification::make()->title(__('Recording restored'))->url(CaptureSessionResource::getUrl('view', ['record' => $record]))->success()->send();
                        } catch (ApiError $e) {
                            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();
                        }
                    }),
                Action::make('eraseNow')->label(__('Delete now'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->authorize(fn (CaptureSession $record): bool => Gate::allows('forceDelete', $record))
                    ->requiresConfirmation()->modalDescription(__('The audio and transcript are erased now. This cannot be undone.'))
                    ->action(function (CaptureSession $record): void {
                        app(RetentionPolicy::class)->deleteNow($record);
                        Notification::make()->title(__('Recording erased'))->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTrash)
            ->emptyStateHeading(__('Nothing deleted in the last :hours hours', ['hours' => CaptureSession::binHours()]));
    }
}
