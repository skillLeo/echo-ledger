<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\ApiCredentials;
use App\Filament\Admin\Pages\ServerUsage;
use App\Filament\Admin\Pages\UpgradeRequestsPage;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Activity;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Usage;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\Staff\StaffAccess;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

/**
 * Where a notice goes when it is clicked (since v1.6.1, docs/DESIGN_SYSTEM.md "Notices"). A toast's target is set
 * where it is sent (`->url()`): the thing it is about when that is not the page already shown (a renamed recording,
 * the project a recording moved to, the page that fixes a problem); a notice about the page itself, or one whose text
 * is there to be copied (a new share link, the payment instructions), has none. A notification in the bell or on
 * Activity goes by its kind, for the person reading it: a page they may open, else nothing.
 */
final class NoticeTargets
{
    /** The page a stored notification is about, for [$viewer]; null when there is none they may open. */
    public static function forNotification(DatabaseNotification $notification, User $viewer): ?string
    {
        $item = ActivityFeed::payload($notification);
        $stored = Notice::internal(is_string($notification->data['viewData']['url'] ?? null) ? $notification->data['viewData']['url'] : null);
        $session = $item['session_id'];
        $plan = EditProfile::settingsUrl('plan');
        $staff = fn (string $permission): bool => StaffAccess::can($permission, $viewer);

        return match ($item['kind']) {
            // The recording, while it is still in the person's Library; else the view that lists what failed.
            'transcript_ready', 'transcript_failed', 'upload_failed' => $session !== null && CaptureSessionResource::getEloquentQuery()->whereKey($session)->exists()
                ? CaptureSessionResource::getUrl('view', ['record' => $session], panel: 'app')
                : ($item['kind'] === 'transcript_ready' ? null : CaptureSessionResource::getUrl('index', ['tab' => 'failed'], panel: 'app')),
            'shared_with_you' => SharedWithMe::getUrl(panel: 'app'),
            'device_paired' => DeviceResource::canAccess() ? DeviceResource::getUrl('index', panel: 'app') : EditProfile::settingsUrl('devices'),
            'budget_warn', 'budget_reached' => Usage::canAccess() ? Usage::getUrl(panel: 'app') : $plan,
            'upgrade_approved', 'upgrade_declined', 'upgrade_receipt_requested' => $plan,
            'upgrade_requested' => $staff('upgrades.manage') ? UpgradeRequestsPage::getUrl(panel: 'admin') : null,
            'unusual_usage' => $staff('customers.view') ? ($stored ?? CustomerResource::getUrl('index', panel: 'admin')) : null,
            'cost_alert' => $staff('usage.view') ? ServerUsage::getUrl(panel: 'admin') : null,
            default => $stored !== null && ! str_contains($stored, '/admin') ? $stored : null,
        };
    }

    /**
     * The page that fixes an error, when there is one: a limit of the plan → Usage (or Account → Plan & usage), a
     * paid feature or a full plan → Plan & usage, a missing provider key → API Credentials for staff who may set it.
     */
    public static function forError(Throwable $e): ?string
    {
        if (! $e instanceof ApiError) {
            return null;
        }
        $plan = EditProfile::settingsUrl('plan');

        return match ($e->apiCode()) {
            'budget_exceeded', 'storage_limit_reached', 'ai_budget_reached' => Usage::canAccess() ? Usage::getUrl(panel: 'app') : $plan,
            'feature_not_in_plan', 'seat_limit_reached', 'device_limit_reached' => $plan,
            'provider_not_configured' => ApiCredentials::canAccess() ? ApiCredentials::getUrl(panel: 'admin') : null,
            default => null,
        };
    }

    /** Where the bell sends a notification with no page of its own: Activity, which lists it. */
    public static function fallback(): string
    {
        return Activity::canAccess() ? Activity::getUrl(panel: 'app') : (Filament::getPanel('app')->getUrl() ?? '/app');
    }
}
