<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Accuracy\AccuracyLab as Lab;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Accuracy Lab (since v1.2, owner only): engines scored against the owner's reference transcripts, per language, and
 * "Use the winners" to put the best one first in Settings → Engine per language.
 */
class AccuracyLab extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Accuracy Lab';

    protected string $view = 'filament.pages.accuracy-lab';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['report' => app(Lab::class)->report((int) CurrentWorkspace::id()), 'settingsUrl' => Settings::getUrl()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('use_winners')->label('Use the winners')->icon(Heroicon::OutlinedTrophy)
                ->requiresConfirmation()
                ->modalDescription('For each language, the engine with the lowest word error rate becomes the first engine in Settings → Engine per language. The others stay as the fallback.')
                ->visible(fn (): bool => app(Lab::class)->report((int) CurrentWorkspace::id())['languages'] !== [])
                ->action(function (): void {
                    $changed = app(Lab::class)->useWinners((int) CurrentWorkspace::id());
                    Notification::make()->title($changed === [] ? 'Nothing to change yet.' : 'First engines now: '.implode(', ', array_map(
                        fn (array $c): string => $c['language'].' → '.$c['provider'], $changed)))->success()->send();
                }),
        ];
    }
}
