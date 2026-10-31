<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Widgets;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\Widget;

/**
 * The "Keep or discard?" card above the recordings list: one summary of the calls still waiting for a decision, as
 * the phone and desktop show it, with a link to the Waiting for decision view. Hidden when nothing is waiting.
 */
class WaitingDecisionsWidget extends Widget
{
    /** Rendered with the page: a card that pops in after the list has loaded would shift the table down. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.waiting-decisions';

    public static function canView(): bool
    {
        return self::summary() !== null;
    }

    /** @return array{line: string|null, reviewUrl: string} */
    protected function getViewData(): array
    {
        return [
            'line' => self::summary(),
            'reviewUrl' => CaptureSessionResource::getUrl('index', ['tab' => 'waiting']),
        ];
    }

    private static function summary(): ?string
    {
        return app(LibraryOrganiser::class)->waitingSummary((int) CurrentWorkspace::id());
    }
}
