<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Widgets;

use App\Enums\SessionStatus;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\Widget;
use Livewire\Attributes\Reactive;

/**
 * "Failed uploads (2) · See why" above the Library (since v1.6, P5.3): one slim line while a recording did not upload
 * or could not be transcribed, opening the Failed uploads view where each one says why, with Retry or Delete. Hidden
 * when nothing failed and while that view is open.
 */
class FailedUploadsWidget extends Widget
{
    /** Rendered with the page: a line that pops in after the list has loaded would shift the table down. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.failed-uploads-chip';

    /** The Library's smart view, handed down by the page. */
    #[Reactive]
    public ?string $activeTab = null;

    public static function canView(): bool
    {
        return self::count() > 0;
    }

    /** @return array{count: int, label: string, url: string, hidden: bool} */
    protected function getViewData(): array
    {
        $count = self::count();

        return [
            'count' => $count,
            'label' => 'Failed uploads ('.number_format($count).')',
            'url' => CaptureSessionResource::getUrl('index', ['tab' => 'failed']),
            'hidden' => $this->activeTab === 'failed',
        ];
    }

    /** Counted like the Library lists them: a recording still running only shows under Waiting. */
    public static function count(): int
    {
        $query = CaptureSession::query()->where('workspace_id', CurrentWorkspace::id())->ofPerson()->where('status', '!=', SessionStatus::Recording->value);

        return app(LibraryOrganiser::class)->applyView($query, 'failed')->count();
    }
}
