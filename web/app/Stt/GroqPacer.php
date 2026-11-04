<?php

declare(strict_types=1);

namespace App\Stt;

use App\Models\ProviderRequest;
use App\Services\SettingsRepository;
use App\Stt\Drivers\GroqWhisperDriver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Groq's rate limits per API key (since v1.5; console.groq.com/docs/rate-limits, checked 2026-11-04): requests per
 * minute and per day, audio seconds per hour and per day, counted over rolling windows from the requests we sent
 * (`provider_requests`; Whisper bills at least 10 s per request). The limits are the plan's (Settings → Groq plan)
 * until Groq's answers say otherwise: `x-ratelimit-limit-requests` is the requests per day, a `x-ratelimit-remaining-*`
 * that is used up waits for its `x-ratelimit-reset-*`, and after a 429 nothing is sent before its `retry-after`.
 * Keys are told apart by a hash; the key itself is never stored here.
 *
 * [waitFor] says how long a request has to wait for room (0: send it now); the driver turns a wait into
 * App\Stt\Exceptions\ProviderBusy, and the transcript goes on later where it stopped.
 */
final class GroqPacer
{
    public const PROVIDER = 'groq';

    /**
     * Limits of Whisper large-v3 per plan: requests per minute and day, audio seconds per hour and day. Free: Groq's
     * rate-limit table. Developer: the figures third-party tables quote from Groq's Developer tab (300 RPM, 200K RPD,
     * 200K audio seconds an hour, 4M a day), which Groq's page did not show in plain text when checked.
     *
     * @var array<string, array{rpm: int, rpd: int, ash: int, asd: int}>
     */
    public const PLANS = [
        'free' => ['rpm' => 20, 'rpd' => 2_000, 'ash' => 7_200, 'asd' => 28_800],
        'developer' => ['rpm' => 300, 'rpd' => 200_000, 'ash' => 200_000, 'asd' => 4_000_000],
    ];

    public const PLAN_LABELS = ['free' => 'Free', 'developer' => 'Developer'];

    public const DEFAULT_PLAN = 'developer';

    /** Request rows are kept this long (model:prune); the longest window is a day. */
    public const KEEP_HOURS = 48;

    /** How long Groq's answers are remembered. */
    private const OBSERVED_HOURS = 48;

    /** A 429 without retry-after or a reset waits this long. */
    private const BLOCK_SECONDS = 60;

    public function __construct(private readonly SettingsRepository $settings) {}

    /** The key's name in the request log: the start of its SHA-256 (tells keys apart, gives nothing away). */
    public static function keyId(string $apiKey): string
    {
        return substr(hash('sha256', $apiKey), 0, 16);
    }

    public function plan(): string
    {
        $plan = $this->settings->get('groq.plan', self::DEFAULT_PLAN);

        return is_string($plan) && isset(self::PLANS[$plan]) ? $plan : self::DEFAULT_PLAN;
    }

    /**
     * The limits in force for [$keyId]: the plan's, with the requests per day Groq last reported.
     *
     * @return array{rpm: int, rpd: int, ash: int, asd: int, plan: string, from_groq: bool}
     */
    public function limits(string $keyId): array
    {
        $limits = self::PLANS[$this->plan()];
        $reported = $this->observed($keyId)['buckets']['requests']['limit'] ?? null;
        $fromGroq = is_int($reported) && $reported > 0;
        if ($fromGroq) {
            $limits['rpd'] = $reported;
        }

        return [...$limits, 'plan' => $this->plan(), 'from_groq' => $fromGroq];
    }

    /** Seconds until a request billed for [$audioMs] fits every limit of [$keyId]; 0 when it can be sent now. */
    public function waitFor(string $keyId, int $audioMs): int
    {
        $need = max(GroqWhisperDriver::MIN_BILLED_MS, $audioMs) / 1000;
        $limits = $this->limits($keyId);
        $waits = [
            $this->windowWait($keyId, 60, $limits['rpm'], 1.0, true),
            $this->windowWait($keyId, 86_400, $limits['rpd'], 1.0, true),
            $this->windowWait($keyId, 3_600, $limits['ash'], $need, false),
            $this->windowWait($keyId, 86_400, $limits['asd'], $need, false),
        ];
        $now = now()->getTimestamp();
        $observed = $this->observed($keyId);
        if (is_numeric($observed['blocked_until'] ?? null) && $observed['blocked_until'] > $now) {
            $waits[] = (int) ceil($observed['blocked_until'] - $now);
        }
        foreach ($observed['buckets'] ?? [] as $bucket => $values) {
            $remaining = $values['remaining'] ?? null;
            $reset = $values['reset_at'] ?? null;
            if (! is_numeric($remaining) || ! is_numeric($reset) || $reset <= $now) {
                continue;
            }
            // Requests: one more fits while one is left. Audio seconds: this request's audio has to fit.
            $short = $bucket === 'requests' ? $remaining < 1 : (str_contains((string) $bucket, 'audio') && $remaining < $need);
            if ($short) {
                $waits[] = (int) ceil($reset - $now);
            }
        }

        return max($waits);
    }

    /** Notes a request Groq answered (it bills [$audioMs], at least 10 s). */
    public function record(string $keyId, int $audioMs): void
    {
        ProviderRequest::query()->create([
            'provider' => self::PROVIDER,
            'key_id' => $keyId,
            'audio_ms' => max(GroqWhisperDriver::MIN_BILLED_MS, $audioMs),
            'requested_at' => now(),
        ]);
    }

    /** Keeps Groq's rate-limit headers (the latest values), and after a 429 the time before which nothing is sent. */
    public function observe(string $keyId, Response $response): void
    {
        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        $state = $this->observed($keyId);
        $now = (float) now()->getTimestamp();
        $seen = false;
        foreach ($headers as $name => $values) {
            if (preg_match('/^x-ratelimit-(limit|remaining|reset)-([a-z0-9-]+)$/', (string) $name, $m) !== 1) {
                continue;
            }
            $value = trim((string) (is_array($values) ? ($values[0] ?? '') : $values));
            $reset = $m[1] === 'reset' ? self::seconds($value) : null;
            $state['buckets'][$m[2]][$m[1] === 'reset' ? 'reset_at' : $m[1]] = match ($m[1]) {
                'limit' => is_numeric($value) ? (int) $value : null,
                'remaining' => is_numeric($value) ? (float) $value : null,
                default => $reset !== null ? $now + $reset : null,
            };
            $seen = true;
        }
        if ($response->status() === 429) {
            $after = self::seconds(trim((string) $response->header('Retry-After')));
            if ($after === null) {
                foreach ($state['buckets'] ?? [] as $values) {
                    if (is_numeric($values['remaining'] ?? null) && $values['remaining'] < 1 && is_numeric($values['reset_at'] ?? null)) {
                        $after = max($after ?? 0.0, $values['reset_at'] - $now);
                    }
                }
            }
            $state['blocked_until'] = $now + max(1.0, $after ?? self::BLOCK_SECONDS);
            $seen = true;
        }
        if ($seen) {
            $state['seen_at'] = $now;
            Cache::put(self::cacheKey($keyId), $state, now()->addHours(self::OBSERVED_HOURS));
        }
    }

    /**
     * This minute's, hour's and day's use of [$keyId] (rolling), for the console.
     *
     * @return array{minute_requests: int, hour_seconds: int, day_seconds: int, day_requests: int}
     */
    public function usage(string $keyId): array
    {
        $since = fn (int $seconds) => ProviderRequest::query()->where('provider', self::PROVIDER)->where('key_id', $keyId)
            ->where('requested_at', '>', now()->subSeconds($seconds));

        return [
            'minute_requests' => $since(60)->count(),
            'hour_seconds' => (int) round(((int) $since(3_600)->sum('audio_ms')) / 1000),
            'day_seconds' => (int) round(((int) $since(86_400)->sum('audio_ms')) / 1000),
            'day_requests' => $since(86_400)->count(),
        ];
    }

    /** The time before which Groq asked us to send nothing (after a 429), when that is still to come. */
    public function blockedUntil(string $keyId): ?Carbon
    {
        $until = $this->observed($keyId)['blocked_until'] ?? null;

        return is_numeric($until) && $until > now()->getTimestamp() ? Carbon::createFromTimestamp((int) ceil((float) $until)) : null;
    }

    /**
     * Groq's reset durations as seconds: "2m59.56s", "7.66s", "1h2m3s", "120ms", or plain seconds. Null when unreadable.
     */
    public static function seconds(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        if (preg_match('/^(?:(\d+(?:\.\d+)?)h)?(?:(\d+(?:\.\d+)?)m(?!s))?(?:(\d+(?:\.\d+)?)s)?(?:(\d+(?:\.\d+)?)ms)?$/', $value, $m) !== 1 || $m[0] === '') {
            return null;
        }

        return (float) ($m[1] ?? 0) * 3600 + (float) ($m[2] ?? 0) * 60 + (float) ($m[3] ?? 0) + (float) ($m[4] ?? 0) / 1000;
    }

    /**
     * Seconds until [$need] (one request, or audio seconds) fits a rolling window of [$window] seconds that allows
     * [$limit]: the oldest requests in the window leave it one by one. A request bigger than the whole limit waits
     * until the window is empty.
     */
    private function windowWait(string $keyId, int $window, int $limit, float $need, bool $count): int
    {
        $query = fn () => ProviderRequest::query()->where('provider', self::PROVIDER)->where('key_id', $keyId)
            ->where('requested_at', '>', now()->subSeconds($window));
        $used = $count ? (float) $query()->count() : ((int) $query()->sum('audio_ms')) / 1000;
        if ($used + $need <= $limit || ($need > $limit && $used <= 0)) {
            return 0;
        }
        $excess = $need > $limit ? $used : $used + $need - $limit;
        $freed = 0.0;
        foreach ($query()->orderBy('requested_at')->orderBy('id')->cursor() as $row) {
            /** @var ProviderRequest $row */
            $freed += $count ? 1.0 : $row->audio_ms / 1000;
            if ($freed >= $excess) {
                return max(1, (int) ceil(now()->diffInSeconds($row->requested_at->copy()->addSeconds($window), false)));
            }
        }

        return $window;
    }

    /** @return array{buckets?: array<string, array{limit?: int|null, remaining?: float|null, reset_at?: float|null}>, blocked_until?: float, seen_at?: float} */
    private function observed(string $keyId): array
    {
        $state = Cache::get(self::cacheKey($keyId));

        return is_array($state) ? $state : [];
    }

    private static function cacheKey(string $keyId): string
    {
        return 'groq-limits:'.$keyId;
    }
}
