<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use Illuminate\Support\Facades\Cache;

/**
 * What the requests of one HTTP-mode session share, apart from the call's words (those are LiveContext and the stored
 * turns): the mode last announced to the app, and how many finals the other side has sent. Counters and names only.
 */
class LiveState
{
    public const TTL_SECONDS = 10800;

    /** @return array{mode: string|null, limited: bool} the mode the app was last told, and whether the budget put it there */
    public function mode(AssistSession $session): array
    {
        $stored = Cache::get('assist.live.mode.'.$session->id);

        return is_array($stored) ? ['mode' => is_string($stored['mode'] ?? null) ? $stored['mode'] : null, 'limited' => (bool) ($stored['limited'] ?? false)] : ['mode' => null, 'limited' => false];
    }

    public function setMode(AssistSession $session, string $mode, bool $limited = false): void
    {
        Cache::put('assist.live.mode.'.$session->id, ['mode' => $mode, 'limited' => $limited], self::TTL_SECONDS);
    }

    /** How many finals the other side has sent: a held question ends when this moves past its own. */
    public function otherFinals(AssistSession $session): int
    {
        return (int) Cache::get('assist.live.other.'.$session->id, 0);
    }

    public function otherFinalSent(AssistSession $session): int
    {
        $key = 'assist.live.other.'.$session->id;
        Cache::add($key, 0, self::TTL_SECONDS);

        return (int) Cache::increment($key);
    }

    public function forget(AssistSession $session): void
    {
        Cache::forget('assist.live.mode.'.$session->id);
        Cache::forget('assist.live.other.'.$session->id);
    }
}
