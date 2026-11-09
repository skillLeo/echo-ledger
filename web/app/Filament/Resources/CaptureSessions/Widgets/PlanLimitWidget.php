<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Widgets;

use App\Filament\Auth\EditProfile;
use App\Filament\Support\SettingsContext;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use Filament\Widgets\Widget;

/**
 * "This month's minutes are used up · Plan & usage" above the Library (since v1.6.1): one slim line while the plan's
 * minutes or storage are used up; on Free without a Groq key (since v1.7) "Transcripts use your own Groq key · Add
 * key". A notice about something still true: no timer, × and a swipe close it until the next visit.
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

    /**
     * Which limit is used up now: 'minutes' (a plan with minutes only; Free has none and uses its own key), 'storage',
     * or, on Free without a Groq key, 'key'; else none.
     */
    public static function reached(): ?string
    {
        $workspace = (int) CurrentWorkspace::id();
        if ($workspace <= 0) {
            return null;
        }
        $limits = app(PlanLimits::class);
        $minutes = $limits->limit($workspace, 'monthly_minutes', CurrentWorkspace::userId());
        if ($minutes !== null && $minutes > 0 && $limits->minutesReached($workspace, CurrentWorkspace::userId())) {
            return 'minutes';
        }
        $storage = $limits->limit($workspace, 'storage_bytes', CurrentWorkspace::userId());
        if ($storage !== null && $limits->storageUsed($workspace) >= $storage) {
            return 'storage';
        }
        $context = SettingsContext::current();

        return $context !== null && $context->flag('free_or_key') && ! $context->flag('key') ? 'key' : null;
    }

    /** @return array{label: string, action: string, url: string, state: string, limit: string|null} */
    protected function getViewData(): array
    {
        $limit = self::reached();

        return [
            'limit' => $limit,
            'label' => match ($limit) {
                'storage' => 'Your plan\'s storage is full',
                'key' => 'Transcripts use your own Groq key.',
                default => 'This month\'s minutes are used up',
            },
            'action' => $limit === 'key' ? 'Add key' : 'Plan & usage',
            'url' => $limit === 'key' ? EditProfile::settingsUrl('transcription-key') : EditProfile::settingsUrl('plan'),
            'state' => $limit.':'.now()->format('Y-m'),
        ];
    }
}
