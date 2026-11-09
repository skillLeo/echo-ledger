<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Widgets;

use App\Filament\Pages\Usage;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * "This month's minutes are used up · See usage" above the Library (since v1.6.1): one slim line while the plan's
 * minutes or storage are used up, opening Usage (or Account → Plan & usage). A notice about something still true: no
 * timer, × and a swipe close it until the next limit is reached or the next visit.
 */
class PlanLimitWidget extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.plan-limit-chip';

    public static function canView(): bool
    {
        return self::reached() !== null;
    }

    /** Which limit is used up now: 'minutes', 'storage' or none. */
    public static function reached(): ?string
    {
        $workspace = (int) CurrentWorkspace::id();
        if ($workspace <= 0) {
            return null;
        }
        $limits = app(PlanLimits::class);
        if ($limits->minutesReached($workspace, CurrentWorkspace::userId())) {
            return 'minutes';
        }
        $storage = $limits->limit($workspace, 'storage_bytes', CurrentWorkspace::userId());

        return $storage !== null && $limits->storageUsed($workspace) >= $storage ? 'storage' : null;
    }

    /** @return array{label: string, url: string, state: string, limit: string|null} */
    protected function getViewData(): array
    {
        $limit = self::reached();

        return [
            'limit' => $limit,
            'label' => $limit === 'storage' ? 'Your plan\'s storage is full' : 'This month\'s minutes are used up',
            'url' => Usage::canAccess() ? Usage::getUrl() : (Filament::getPanel('app')->getProfileUrl() ?? '/app/profile').'?tab=plan',
            'state' => $limit.':'.now()->format('Y-m'),
        ];
    }
}
