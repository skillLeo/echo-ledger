<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\RelationManagers;

use App\Models\ShareLink;
use App\Services\SettingsRepository;
use App\Services\ShareLinks;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

/** The recording's share links (v1.1): when they expire, how often they were opened, and Revoke. */
class ShareLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'shareLinks';

    protected static ?string $title = 'Share links';

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();

        return $table
            ->recordTitle(fn (ShareLink $link): string => 'share link '.$link->created_at->copy()->setTimezone($tz)->format('M j, g:i A'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No share links')
            ->emptyStateDescription('Use “Share” at the top of the page to make a view-only link.')
            ->columns([
                TextColumn::make('created_at')->label('Made')->dateTime('M j, Y · g:i A', $tz),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (ShareLink $link): string => $link->revoked_at !== null ? 'Revoked' : ($link->isActive() ? 'Active' : 'Expired'))
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('expires_at')->label('Expires')->dateTime('M j, Y · g:i A', $tz),
                IconColumn::make('password_hash')->label('Password')->boolean()->state(fn (ShareLink $link): bool => $link->password_hash !== null),
                IconColumn::make('include_audio')->label('Audio')->boolean(),
                TextColumn::make('view_count')->label('Views')->numeric(),
                TextColumn::make('last_viewed_at')->label('Last opened')->since()->placeholder('Never'),
                TextColumn::make('created_by')->label('Made by')->placeholder('—'),
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
            ]);
    }
}
