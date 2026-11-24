<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        // Counted by the database, so the number is exact however many there are, and no item is read (BE-04 review).
        $count = $this->unread($user, false)->count() + ($workspaceId > 0 ? app(LibraryOrganiser::class)->waitingCount($workspaceId) : 0);

        return ['count' => $count, 'label' => self::label($count), 'tone' => $this->unread($user, true)->exists() ? 'danger' : 'accent'];
    }

    /**
     * The person's unread items that need action (needsAction's rule, in SQL on the item's kind), or only the failures
     * (isFailure's).
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    private function unread(User $user, bool $failuresOnly): MorphMany
    {
        $kind = 'data->viewData->kind';
        $query = $user->unreadNotifications();
        $query->where(function ($q) use ($kind, $failuresOnly): void {
            $q->where($kind, 'like', '%fail%')->orWhere($kind, 'like', '%error%');
            if (! $failuresOnly) {
                $q->orWhere($kind, 'like', '%problem%')->orWhereIn($kind, ['budget_reached', 'missed_recording', ...self::UPGRADE_REPLIES]);
            }
        });

        return $query;
    }

    /** Nothing at 0, the number up to 99, then "99+" (since 2.0.0-r; "9+" above nine before). */
    public static function label(int $count): ?string
    {
        return $count <= 0 ? null : ($count > 99 ? '99+' : (string) $count);
    }
}
