<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\Device;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * What `POST /api/usage` believes (docs/API.md): the monthly limit is a total for the whole server, so a report must not
 * be able to invent minutes. The server notes each `stt/token` and `live-translate/token` it gives a device; the device's
 * reports since its first grant of the last 48 hours cannot add up to more than the time since that grant, plus 5 minutes.
 *
 * Kept in the cache per device and per hour, so it stays small however many tokens and reports come: for each hour the
 * first grant in it and the milliseconds reported in it.
 */
final class StreamingGrants
{
    public const WINDOW_HOURS = 48;

    public const SLACK_SECONDS = 300;

    private const HOUR_SECONDS = 3600;

    /** A token was given to the device. */
    public function note(Device $device): void
    {
        $this->change($device, function (array $state, int $now): array {
            $state['grants'][intdiv($now, self::HOUR_SECONDS)] ??= $now;

            return [$state, null];
        });
    }

    /** How much of a report of [$streamedMs] counts, 0 to all of it; what counts is taken from what the device has left. */
    public function take(Device $device, int $streamedMs): int
    {
        return (int) $this->change($device, function (array $state, int $now) use ($streamedMs): array {
            $first = $state['grants'] === [] ? null : min($state['grants']);
            if ($first === null) {
                return [$state, 0];
            }
            $since = intdiv($first, self::HOUR_SECONDS);
            $reported = array_sum(array_filter($state['reports'], fn (int $hour): bool => $hour >= $since, ARRAY_FILTER_USE_KEY));
            $counted = max(0, min($streamedMs, ($now - $first + self::SLACK_SECONDS) * 1000 - $reported));
            $hour = intdiv($now, self::HOUR_SECONDS);
            $state['reports'][$hour] = ($state['reports'][$hour] ?? 0) + $counted;

            return [$state, $counted];
        });
    }

    /**
     * Reads the device's state without what has left the window, changes it, stores it. One change at a time per device,
     * so two reports at once cannot both spend what is left.
     *
     * @param  Closure(array{grants: array<int, int>, reports: array<int, int>}, int): array{0: array{grants: array<int, int>, reports: array<int, int>}, 1: int|null}  $change
     */
    private function change(Device $device, Closure $change): ?int
    {
        $key = 'streaming-grants:'.$device->id;

        return Cache::lock($key.':lock', 10)->block(3, function () use ($key, $change): ?int {
            $now = now()->getTimestamp();
            $oldest = $now - self::WINDOW_HOURS * self::HOUR_SECONDS;
            /** @var array{grants: array<int, int>, reports: array<int, int>} $state */
            $state = Cache::get($key, ['grants' => [], 'reports' => []]);
            $state['grants'] = array_filter($state['grants'], fn (int $at): bool => $at >= $oldest);
            $state['reports'] = array_filter($state['reports'], fn (int $hour): bool => $hour >= intdiv($oldest, self::HOUR_SECONDS), ARRAY_FILTER_USE_KEY);
            [$state, $answer] = $change($state, $now);
            // An hour longer than the window, as the last hour's rows are read while they count.
            Cache::put($key, $state, now()->addHours(self::WINDOW_HOURS + 1));

            return $answer;
        });
    }
}
