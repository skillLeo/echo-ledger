<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\FullConsoleOnly;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\SharingActions;
use App\Filament\Support\V2Tables;
use App\Http\Controllers\Api\ShareController;
use App\Models\ShareLink;
use App\Services\SettingsRepository;
use App\Services\ShareLinks;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Shares (v1.5): every link to the person's recordings and projects in the workspace, who may open it, its views, and Revoke. */
class Shares extends Page implements HasTable
{
    use FullConsoleOnly;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Link;

    protected static string|UnitEnum|null $navigationGroup = 'More';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Shares';

    /** Since v2.0 the page is called My links, as in the new frame's sidebar and breadcrumb; classic keeps Shares. */
    public function getTitle(): string|Htmlable
    {
        return ConsoleV2::shell() ? __('My links') : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();
        // W-201: the table fits the page at 1024 px in both designs. What was shared wraps, Downloads, Last opened and
        // Made are wide-only columns (Made still sorts when shown), and the row's actions are in one ⋮ menu. The new
        // frame also dates Expires by its one date rule, in the console's language (only the new frame has another).
        $v2 = ConsoleV2::shell();
        // A column that made the table too wide: under Columns in the new frame; in classic only on a wide window (from
        // 1280 px), since classic has no Columns menu (Filament draws its labels with x-html, which classic pages never contain).
        $wide = fn (Column $column): Column => $v2 ? V2Tables::inColumns($column) : $column->visibleFrom('xl');
        $actions = [
            Action::make('log')->label(__('Access log'))->icon(Heroicon::OutlinedListBullet)->color('gray')
                ->modalHeading(__('Access log'))->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->modalContent(fn (ShareLink $link): View => view('filament.pages.share-log', [
                    'views' => $link->views()->latest('viewed_at')->limit(200)->get(), 'tz' => $tz,
                ])),
            Action::make('revoke')->label(__('Revoke'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (ShareLink $link): bool => $link->isOpen())
                ->requiresConfirmation()->modalDescription(__('The link stops working at once.'))
                ->action(fn (ShareLink $link) => app(ShareLinks::class)->revoke($link)),
        ];

        return $table
            ->query(fn (): Builder => ShareController::workspaceLinks((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId())->with(['captureSession', 'client']))
            ->defaultSort('created_at', 'desc')
            ->description(__('Links to your recordings and projects. Revoking one stops it at once; the address is shown only when it is made.'))
            ->columns([
                TextColumn::make('what')->label(__('Shared'))->limit(60)
                    ->state(fn (ShareLink $link): string => $link->kind() === 'project' ? (string) $link->client?->name : (string) $link->captureSession?->displayTitle())
                    ->description(fn (ShareLink $link): string => $link->kind() === 'project' ? __('Project') : __('Recording'))
                    ->url(fn (ShareLink $link): ?string => match (true) {
                        $link->client !== null => ClientResource::getUrl('view', ['record' => $link->client]),
                        $link->captureSession !== null && ! $link->captureSession->trashed() => CaptureSessionResource::getUrl('view', ['record' => $link->captureSession]),
                        default => null,
                    })
                    ->wrap(),
                TextColumn::make('audience')->label(__('Who can open it'))->wrap()
                    ->state(fn (ShareLink $link): string => SharingActions::audienceLabel($link))
                    ->formatStateUsing(fn (string $state): string => __($state)),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->state(fn (ShareLink $link): string => $link->revoked_at !== null ? 'Revoked' : ($link->isOpen() ? 'Active' : 'Expired'))
                    ->formatStateUsing(fn (string $state): string => __($state))
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('allow_download')->label(__('Downloads'))->state(fn (ShareLink $link): string => $link->allow_download ? __('Allowed') : __('Read and listen'))
                    ->tap($wide),
                // The hour on a line of its own: a link can last an hour, and the row keeps room for Revoke.
                TextColumn::make('expires_at')->label(__('Expires'))->date('M j, Y', $tz)->placeholder(__('Never'))
                    ->description(fn (ShareLink $link): ?string => $link->expires_at?->setTimezone($tz)->format('g:i A'))
                    ->when($v2, fn (TextColumn $column): TextColumn => V2Tables::date($column, $tz)),
                TextColumn::make('view_count')->label(__('Views'))->numeric()->sortable(),
                TextColumn::make('last_viewed_at')->label(__('Last opened'))->since()->placeholder(__('Never'))->tap($wide),
                TextColumn::make('created_at')->label(__('Made'))->since()->sortable()->tap($wide),
            ])
            // The filter button's badge shows once a filter is on, not as a 0 (see the theme).
            ->filtersTriggerAction(fn (Action $action, Table $table): Action => $action
                ->extraAttributes(fn (): array => ['data-active-filters' => (string) $table->getActiveFiltersCount()]))
            ->filters([
                SelectFilter::make('state')->label(__('Status'))->options(['active' => __('Active'), 'ended' => __('Revoked or expired')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('revoked_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                        'ended' => $query->where(fn (Builder $q) => $q->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now())),
                        default => $query,
                    }),
            ])
            ->recordActions(V2Tables::actions($actions))
            ->emptyStateIcon(Heroicon::OutlinedLink)
            ->emptyStateHeading(__('No share links yet'))
            ->emptyStateDescription(__('Use Share on a recording or a project to make a view-only link.'));
    }
}
