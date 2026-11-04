<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Widgets;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\Widget;

/**
 * The calls still waiting for Keep or Discard, above the recordings list: since v1.5 one slim line, "4 calls waiting ·
 * Review", where Review opens the Waiting for decision view (the details are its tooltip). Hidden when nothing waits.
 */
class WaitingDecisionsWidget extends Widget
{
    /** Rendered with the page: a line that pops in after the list has loaded would shift the table down. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.waiting-chip';

    public static function canView(): bool
    {
        return self::count() > 0;
    }

    /** @return array{label: string, detail: string|null, reviewUrl: string} */
    protected function getViewData(): array
    {
        $count = self::count();

        return [
            'label' => $count === 1 ? '1 call waiting' : "{$count} calls waiting",
            'detail' => app(LibraryOrganiser::class)->waitingSummary((int) CurrentWorkspace::id()),
            'reviewUrl' => CaptureSessionResource::getUrl('index', ['tab' => 'waiting']),
        ];
    }

    private static function count(): int
    {
        return app(LibraryOrganiser::class)->waitingCount((int) CurrentWorkspace::id());
    }
}
