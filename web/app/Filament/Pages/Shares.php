<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\SharingActions;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Shares (v1.5): every link to the workspace's recordings and projects, who may open it, its views, and Revoke. */
class Shares extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'More';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Shares';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();

        return $table
            ->query(fn (): Builder => ShareController::workspaceLinks((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId())->with(['captureSession', 'client']))
            ->defaultSort('created_at', 'desc')
            ->description('Links to your recordings and projects. Revoking one stops it at once; the address is shown only when it is made.')
            ->columns([
                TextColumn::make('what')->label('Shared')->limit(60)
                    ->state(fn (ShareLink $link): string => $link->kind() === 'project' ? (string) $link->client?->name : (string) $link->captureSession?->displayTitle())
                    ->description(fn (ShareLink $link): string => $link->kind() === 'project' ? 'Project' : 'Recording')
                    ->url(fn (ShareLink $link): ?string => match (true) {
                        $link->client !== null => ClientResource::getUrl('view', ['record' => $link->client]),
                        $link->captureSession !== null && ! $link->captureSession->trashed() => CaptureSessionResource::getUrl('view', ['record' => $link->captureSession]),
                        default => null,
                    }),
                TextColumn::make('audience')->label('Who can open it')->wrap()
                    ->state(fn (ShareLink $link): string => SharingActions::audienceLabel($link)),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (ShareLink $link): string => $link->revoked_at !== null ? 'Revoked' : ($link->isActive() ? 'Active' : 'Expired'))
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('allow_download')->label('Downloads')->state(fn (ShareLink $link): string => $link->allow_download ? 'Allowed' : 'Read and listen'),
                TextColumn::make('expires_at')->label('Expires')->dateTime('M j, Y · g:i A', $tz)->placeholder('Never'),
                TextColumn::make('view_count')->label('Views')->numeric()->sortable(),
                TextColumn::make('last_viewed_at')->label('Last opened')->since()->placeholder('Never'),
                TextColumn::make('created_at')->label('Made')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Status')->options(['active' => 'Active', 'ended' => 'Revoked or expired'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('revoked_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                        'ended' => $query->where(fn (Builder $q) => $q->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now())),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('log')->label('Access log')->icon(Heroicon::OutlinedListBullet)->color('gray')
                    ->modalHeading('Access log')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (ShareLink $link): View => view('filament.pages.share-log', [
                        'views' => $link->views()->latest('viewed_at')->limit(200)->get(), 'tz' => $tz,
                    ])),
                Action::make('revoke')->label('Revoke')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (ShareLink $link): bool => $link->isActive())
                    ->requiresConfirmation()->modalDescription('The link stops working at once.')
                    ->action(fn (ShareLink $link) => app(ShareLinks::class)->revoke($link)),
            ])
            ->emptyStateIcon(Heroicon::OutlinedLink)
            ->emptyStateHeading('No share links yet')
            ->emptyStateDescription('Use Share on a recording or a project to make a view-only link.');
    }
}
