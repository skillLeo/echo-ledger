<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\RelationManagers;

use App\Filament\Support\ConsoleV2;
use App\Filament\Support\SharingActions;
use App\Filament\Support\V2Tables;
use App\Models\CaptureSession;
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
use Illuminate\Database\Eloquent\Model;

/** The recording's share links (v1.1; audiences and downloads since v1.5): when they expire, how often they were opened, and Revoke. */
class ShareLinksRelationManager extends RelationManager
{
    /** A recording without sound has nothing to share: no share links section. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! ($ownerRecord instanceof CaptureSession && $ownerRecord->hasNoAudio()) && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    protected static string $relationship = 'shareLinks';

    protected static ?string $title = 'Share links';

    /** Rendered with the page: a card that fills in after the transcript has loaded would jump. */
    protected static bool $isLazy = false;

    /** In the console's language under the new frame (English, as before, in classic). */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(static::$title);
    }

    public function table(Table $table): Table
    {
        $tz = app(SettingsRepository::class)->displayTimezone();
        // Since v2.0 (the new frame, W-201): the table fits the page at 1024 px. The dates by the one date rule (the
        // time on a second line), Made by and Downloads in Columns only, and the row's actions in one ⋮ menu. Its words
        // are in the console's language (only the new frame has another). Classic is as it was.
        $v2 = ConsoleV2::shell();
        $date = fn (TextColumn $column): TextColumn => V2Tables::date($column, $tz);
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
            ->recordTitle(fn (ShareLink $link): string => 'share link '.$link->created_at->copy()->setTimezone($tz)->format('M j, g:i A'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedLink)
            ->emptyStateHeading(__('No share links'))
            ->emptyStateDescription(__('Use “Share” at the top of the page to make a view-only link.'))
            ->columns([
                TextColumn::make('created_at')->label(__('Made'))->dateTime('M j, Y · g:i A', $tz)->when($v2, $date),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->state(fn (ShareLink $link): string => $link->revoked_at !== null ? 'Revoked' : ($link->isOpen() ? 'Active' : 'Expired'))
                    ->formatStateUsing(fn (string $state): string => __($state))
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('expires_at')->label(__('Expires'))->dateTime('M j, Y · g:i A', $tz)->placeholder(__('Never'))->when($v2, $date),
                // Since v1.5: who may open it (anyone, a password, named people) and whether files can be downloaded.
                TextColumn::make('audience')->label(__('Who can open it'))->wrap()->state(fn (ShareLink $link): string => SharingActions::audienceLabel($link))
                    ->formatStateUsing(fn (string $state): string => __($state)),
                IconColumn::make('allow_download')->label(__('Downloads'))->boolean()->when($v2, V2Tables::inColumns(...)),
                TextColumn::make('view_count')->label(__('Views'))->numeric(),
                TextColumn::make('last_viewed_at')->label(__('Last opened'))->since()->placeholder(__('Never')),
                TextColumn::make('created_by')->label(__('Made by'))->placeholder('—')->when($v2, V2Tables::inColumns(...)),
            ])
            ->recordActions($v2 ? V2Tables::actions($actions) : $actions);
    }
}
