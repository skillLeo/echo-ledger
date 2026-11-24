<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The bell's number in the app (since v1.6, P6): only what needs the person, as the phone's Activity tab and the
 * desktop count it: unread problems (a failed transcript or upload, the monthly limit reached, since v1.8 a missed
 * recording), unread answers to an upgrade request, and calls waiting for the person's choice after a call. Never
 * finished transcripts, new devices or shares. Since 2.0.0-r (BE-04) it is the one number on the Activity icon of
 * every app, `GET /api/badge`: up to 99, then "99+", red only when a failure is among it.
 */
final class ActionBadge
{
    public const UPGRADE_REPLIES = ['upgrade_approved', 'upgrade_declined', 'upgrade_receipt_requested'];

    /** How many unread notifications are read for the number (newest first): more than "99+" ever needs. */
    private const LOOK_AT = 200;

    /**
     * A problem the person has to look at: the phone's rule (fail, error, problem), the monthly limit reached and, since
     * v1.8, a call an app saw but did not record (docs/API.md: it needs action in every app).
     */
    public static function isProblem(?string $kind): bool
    {
        $kind = strtolower((string) $kind);

        return str_contains($kind, 'fail') || str_contains($kind, 'error') || str_contains($kind, 'problem') || in_array($kind, ['budget_reached', 'missed_recording'], true);
    }

    public static function needsAction(?string $kind): bool
    {
        return self::isProblem($kind) || in_array($kind, self::UPGRADE_REPLIES, true);
    }

    /** A failure among what is counted turns the badge red (`tone` danger): a transcript or an upload that failed. */
    public static function isFailure(?string $kind): bool
    {
        $kind = strtolower((string) $kind);

        return str_contains($kind, 'fail') || str_contains($kind, 'error');
    }

    public function count(User $user, int $workspaceId): int
    {
        return $this->badge($user, $workspaceId)['count'];
    }

    /**
     * `GET /api/badge` (contract 2.0.0-r, BE-04): the number, its label and its tone, for [$user] in [$workspaceId].
     *
     * @return array{count: int, label: string|null, tone: string}
     */
    public function badge(User $user, int $workspaceId): array
    {
        $kinds = $user->unreadNotifications()->latest()->limit(self::LOOK_AT)->get()
            ->map(fn (DatabaseNotification $n): ?string => ActivityFeed::kindOf($n))->filter(fn (?string $kind): bool => self::needsAction($kind));
        $count = $kinds->count() + ($workspaceId > 0 ? app(LibraryOrganiser::class)->waitingCount($workspaceId) : 0);

        return ['count' => $count, 'label' => self::label($count), 'tone' => $kinds->contains(fn (?string $kind): bool => self::isFailure($kind)) ? 'danger' : 'accent'];
    }

    /** Nothing at 0, the number up to 99, then "99+" (since 2.0.0-r; "9+" above nine before). */
    public static function label(int $count): ?string
    {
        return $count <= 0 ? null : ($count > 99 ? '99+' : (string) $count);
    }
}
