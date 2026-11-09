<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Widgets;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
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

    /** @return array{label: string, detail: string|null, reviewUrl: string, state: string} */
    protected function getViewData(): array
    {
        $count = self::count();
        $detail = app(LibraryOrganiser::class)->waitingSummary((int) CurrentWorkspace::id());

        return [
            // Closed, the line comes back when another call waits (the calls waiting, not their growing lengths).
            'state' => substr(md5(app(LibraryOrganiser::class)->applyView(CaptureSession::query()->where('workspace_id', CurrentWorkspace::id())->ofPerson(), 'waiting')
                ->reorder()->orderBy('capture_sessions.id')->limit(500)->pluck('capture_sessions.id')->implode(',')), 0, 12),
            'label' => $count === 1 ? '1 call waiting' : "{$count} calls waiting",
            'detail' => $detail,
            'reviewUrl' => CaptureSessionResource::getUrl('index', ['tab' => 'waiting']),
        ];
    }

    private static function count(): int
    {
        return app(LibraryOrganiser::class)->waitingCount((int) CurrentWorkspace::id());
    }
}
