<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The bell's number in the app (since v1.6, P6): only what needs the person, as the phone's Activity tab and the
 * desktop count it: unread problems (a failed transcript or upload, the monthly limit reached), unread answers to an
 * upgrade request, and calls waiting for Keep or Discard. Never finished transcripts, new devices or shares. Above nine
 * it says "9+".
 */
final class ActionBadge
{
    public const UPGRADE_REPLIES = ['upgrade_approved', 'upgrade_declined'];

    /** How many unread notifications are read for the number (newest first): more than "9+" ever needs. */
    private const LOOK_AT = 50;

    /** A problem the person has to look at: the phone's rule (fail, error, problem) and the monthly limit reached. */
    public static function isProblem(?string $kind): bool
    {
        $kind = strtolower((string) $kind);

        return str_contains($kind, 'fail') || str_contains($kind, 'error') || str_contains($kind, 'problem') || $kind === 'budget_reached';
    }

    public static function needsAction(?string $kind): bool
    {
        return self::isProblem($kind) || in_array($kind, self::UPGRADE_REPLIES, true);
    }

    public function count(User $user, int $workspaceId): int
    {
        $unread = $user->unreadNotifications()->latest()->limit(self::LOOK_AT)->get()
            ->filter(fn (DatabaseNotification $n): bool => self::needsAction(ActivityFeed::kindOf($n)))->count();

        return $unread + ($workspaceId > 0 ? app(LibraryOrganiser::class)->waitingCount($workspaceId) : 0);
    }

    /** Nothing at 0, the number up to 9, then "9+". */
    public static function label(int $count): ?string
    {
        return $count <= 0 ? null : ($count > 9 ? '9+' : (string) $count);
    }
}
