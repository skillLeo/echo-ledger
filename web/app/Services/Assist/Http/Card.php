<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The suggestion on the card, shared by the requests of one HTTP-mode session (docs/API_ASSIST.md 1.9.0-q): whoever
 * ends a suggestion sends its end, and the stream that was writing it then stops without another event about it. The
 * card is the one place that decides who ends it: every change is made with the session's lock held, so exactly one of
 * "the writer finishes it" and "another stream supersedes or cancels it" happens.
 *
 * A record is `{id, status, kind, turn_id, model, mode, input_tokens, started_at}`; `status` is `writing`, `done`,
 * `skipped`, `failed`, or what ended it (`superseded`, `cancelled`). Numbers and ids only: never the call's words.
 */
class Card
{
    public const TTL_SECONDS = 10800;

    /** How long a request waits for the lock; past it the change is made anyway (the lock only orders the requests). */
    private const LOCK_WAIT_SECONDS = 3;

    /** @return array<string, mixed>|null */
    public function current(AssistSession $session): ?array
    {
        $record = Cache::get(self::key($session));

        return is_array($record) ? $record : null;
    }

    /**
     * Takes the card for a new suggestion. A suggestion still being written on it ends `superseded` here: the returned
     * record is what the caller sends the end for, before its own `suggestion_start`. With [$only] (an upgrade), the
     * card is taken only while that suggestion is on it in the status [$onlyStatus] (`done` for an upgrade; `writing`
     * for the fallback that takes over from its own suggestion).
     *
     * @param  array<string, mixed>  $record  the new suggestion, status `writing`
     * @return array{claimed: bool, ended: array<string, mixed>|null}
     */
    public function claim(AssistSession $session, array $record, ?string $only = null, string $onlyStatus = 'done'): array
    {
        return $this->locked($session, function () use ($session, $record, $only, $onlyStatus): array {
            $old = $this->current($session);
            if ($only !== null && ($old === null || $old['id'] !== $only || $old['status'] !== $onlyStatus)) {
                return ['claimed' => false, 'ended' => null];
            }
            $ended = null;
            if ($old !== null && $old['status'] === 'writing') {
                $ended = [...$old, 'status' => 'superseded'];
                Cache::put(self::endKey($session, (string) $old['id']), 'superseded', 900);
            }
            Cache::put(self::key($session), $record, self::TTL_SECONDS);

            return ['claimed' => true, 'ended' => $ended];
        });
    }

    /** Whether [$id] is still the suggestion being written on the card (nobody has ended it). */
    public function writing(AssistSession $session, string $id): bool
    {
        $record = $this->current($session);

        return $record !== null && $record['id'] === $id && $record['status'] === 'writing';
    }

    /** Whether [$id] is on the card, finished (the draft an upgrade may replace). */
    public function done(AssistSession $session, string $id): bool
    {
        $record = $this->current($session);

        return $record !== null && $record['id'] === $id && $record['status'] === 'done';
    }

    /**
     * The writer ends its own suggestion ([$status] `done`, `skipped` or `failed`). False when someone else ended it
     * first: the writer then says nothing about it.
     */
    public function finish(AssistSession $session, string $id, string $status): bool
    {
        return $this->locked($session, function () use ($session, $id, $status): bool {
            $record = $this->current($session);
            if ($record === null || $record['id'] !== $id || $record['status'] !== 'writing') {
                return false;
            }
            Cache::put(self::key($session), [...$record, 'status' => $status], self::TTL_SECONDS);

            return true;
        });
    }

    /**
     * Ends the suggestion being written on the card ([$id], or whichever is on it when null) as [$status] (`cancelled`):
     * the returned record is what the caller sends the end for.
     *
     * @return array<string, mixed>|null null when nothing is being written
     */
    public function end(AssistSession $session, ?string $id, string $status): ?array
    {
        return $this->locked($session, function () use ($session, $id, $status): ?array {
            $record = $this->current($session);
            if ($record === null || $record['status'] !== 'writing' || ($id !== null && $record['id'] !== $id)) {
                return null;
            }
            $ended = [...$record, 'status' => $status];
            Cache::put(self::key($session), $ended, self::TTL_SECONDS);
            Cache::put(self::endKey($session, (string) $record['id']), $status, 900);

            return $ended;
        });
    }

    /** What ended [$id] while its writer was still working (`superseded` or `cancelled`), or null. */
    public function endedAs(AssistSession $session, string $id): ?string
    {
        $status = Cache::get(self::endKey($session, $id));

        return is_string($status) ? $status : null;
    }

    /** The writer's progress, for the estimate in an end another stream sends: characters written so far. */
    public function progress(AssistSession $session, string $id, int $chars): void
    {
        Cache::put(self::progressKey($session, $id), $chars, 900);
    }

    public function chars(AssistSession $session, string $id): int
    {
        return (int) Cache::get(self::progressKey($session, $id), 0);
    }

    public function forget(AssistSession $session): void
    {
        Cache::forget(self::key($session));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $change
     * @return T
     */
    private function locked(AssistSession $session, \Closure $change): mixed
    {
        try {
            return Cache::lock('assist.live.card.lock.'.$session->id, 10)->block(self::LOCK_WAIT_SECONDS, $change);
        } catch (LockTimeoutException) {
            return $change();
        }
    }

    private static function key(AssistSession $session): string
    {
        return 'assist.live.card.'.$session->id;
    }

    private static function endKey(AssistSession $session, string $id): string
    {
        return 'assist.live.card.end.'.$session->id.'.'.$id;
    }

    private static function progressKey(AssistSession $session, string $id): string
    {
        return 'assist.live.card.chars.'.$session->id.'.'.$id;
    }
}
