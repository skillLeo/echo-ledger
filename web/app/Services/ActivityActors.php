<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SentItem;
use App\Models\User;
use App\Services\Accounts\Avatars;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The person an Activity item is about (contract 2.0.0-aa, UX-18), so every app can show their picture in the item's
 * icon place. The source is the item's own `actor_id` (stored for a share's sender, a staff receipt and unusual use).
 * A share from before `actor_id` falls back to its sender, as Sent to keeps them. Only the recipient's own items are
 * read, and only people who are still there.
 */
final class ActivityActors
{
    /**
     * @param  iterable<DatabaseNotification>  $items  [$recipient]'s items; anyone else's are skipped
     * @return array<string, User> by the item's id
     */
    public static function of(User $recipient, iterable $items): array
    {
        $ids = [];
        $shares = [];
        foreach ($items as $item) {
            if ($item->notifiable_type !== $recipient->getMorphClass() || (int) $item->notifiable_id !== $recipient->id) {
                continue;
            }
            $actor = $item->data['viewData']['actor_id'] ?? null;
            $session = $item->data['viewData']['session_id'] ?? null;
            if (is_int($actor) || (is_string($actor) && ctype_digit($actor))) {
                $ids[$item->id] = (int) $actor;
            } elseif (ActivityFeed::kindOf($item) === 'shared_with_you' && is_string($session)) {
                $shares[$item->id] = $session;
            }
        }
        if ($shares !== []) {
            // Only what was sent to this recipient: who else a recording went to is never read here. The newest send wins.
            $senders = SentItem::query()->where('to_user_id', $recipient->id)->whereIn('capture_session_id', array_values(array_unique($shares)))
                ->orderBy('sent_at')->pluck('from_user_id', 'capture_session_id');
            foreach ($shares as $id => $session) {
                if (isset($senders[$session])) {
                    $ids[$id] = (int) $senders[$session];
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $found = User::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');
        $people = [];
        foreach ($ids as $id => $userId) {
            $person = $found->get($userId);
            if ($person instanceof User) {
                $people[$id] = $person;
            }
        }

        return $people;
    }

    /**
     * An item's `actor`: the person as the API shows people elsewhere (2.0.0-l and 2.0.0-r §6), the picture by its
     * signed, versioned URL, given only here where the person is shown. Null when the item is about nobody.
     *
     * @return array{id: int, name: string, avatar_url: string|null, avatar_version: int|null, avatar_color: int|null}|null
     */
    public static function payload(?User $actor): ?array
    {
        return $actor === null ? null : ['id' => $actor->id, 'name' => (string) $actor->name, ...Avatars::of($actor)];
    }
}
