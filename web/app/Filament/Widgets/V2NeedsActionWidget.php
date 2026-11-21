<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Support\ConsoleV2;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Widgets\Widget;

/**
 * Since v2.0 (docs/PLAN_V2_PARITY.md section 2, WEB-HOM-005): what needs the person, as one quiet line on Home, only in
 * the new design and only when something does: recordings waiting for a decision, failed uploads and recordings not
 * transcribed yet, each a link to its Library view. The counts are the Library's own (LibraryOrganiser).
 */
class V2NeedsActionWidget extends Widget
{
    protected static ?int $sort = -9;

    /** Rendered with the page, as the setup checklist: a line that pops in later would move the cards below it. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.v2.home-needs';

    public static function canView(): bool
    {
        return ConsoleV2::shell() && auth()->check() && self::items() !== [];
    }

    /**
     * What needs the person, in this order, each with its words and its Library view; nothing that counts 0.
     *
     * @return list<array{key: string, words: string, url: string}>
     */
    public static function items(): array
    {
        $organiser = app(LibraryOrganiser::class);
        $workspace = (int) CurrentWorkspace::id();
        $counts = $organiser->counts($workspace);
        $all = [
            'waiting' => [(int) ($counts['waiting'] ?? 0), ':count waiting for a decision|:count waiting for a decision'],
            'failed' => [(int) ($counts['failed'] ?? 0), '1 failed upload|:count failed uploads'],
            'untranscribed' => [$organiser->untranscribedCount($workspace), '1 not transcribed|:count not transcribed'],
        ];
        $items = [];
        foreach ($all as $key => [$count, $words]) {
            if ($count > 0) {
                $items[] = ['key' => $key, 'words' => trans_choice($words, $count, ['count' => $count]),
                    'url' => CaptureSessionResource::getUrl('index', ['activeTab' => $key])];
            }
        }

        return $items;
    }
}
