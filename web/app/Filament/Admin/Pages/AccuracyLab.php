<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\Notice as Notification;
use App\Services\Accuracy\AccuracyLab as Lab;
use App\Services\Staff\StaffAccess;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Accuracy Lab (since v1.2, owner only): engines scored against the owner's reference transcripts, per category (since
 * v1.5 Urdu, English and mixed, the original transcript and the Roman Urdu view apart), and "Apply winners" to put the
 * best one first in Settings → Engine per language.
 */
class AccuracyLab extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Beaker;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Accuracy Lab';

    protected string $view = 'filament.pages.accuracy-lab';

    public static function canAccess(): bool
    {
        return StaffAccess::can('devices.reports');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['report' => app(Lab::class)->report((int) CurrentWorkspace::id()), 'settingsUrl' => Settings::getUrl(panel: 'admin')];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply_winners')->label('Apply winners')->icon(Heroicon::OutlinedTrophy)
                ->requiresConfirmation()
                ->modalDescription('For each category, the engine with the lowest word error rate on the original transcript (the Roman Urdu view breaks ties) becomes the first engine of its list in Settings → Engine per language: Urdu, English, Auto for mixed calls, and Arabic. The other engines keep their order behind it. Engines not scored yet never win, and the list the other languages share is left as it is.')
                ->visible(fn (): bool => app(Lab::class)->report((int) CurrentWorkspace::id())['languages'] !== [])
                ->action(function (): void {
                    $changed = app(Lab::class)->useWinners((int) CurrentWorkspace::id());
                    Notification::make()->title($changed === [] ? 'Nothing to change yet.' : 'First engine now: '.implode(', ', array_map(
                        fn (array $c): string => $c['language'].' → '.$c['provider'], $changed)))
                        ->url($changed !== [] && Settings::canAccess() ? Settings::getUrl(panel: 'admin') : null)->success()->send();
                }),
        ];
    }
}
