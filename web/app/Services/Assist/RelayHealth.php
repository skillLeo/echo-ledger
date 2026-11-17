<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether the Live Assist relay answers its health check (`GET https://<relay host>/healthz` →
 * `{"status": "ok", "version": "…"}`), remembered for a minute per relay host so a session start never waits on it
 * more than once a minute (docs/API_ASSIST.md 1.9.0-q, "Transport").
 */
class RelayHealth
{
    /** A check this recent decides without asking again. */
    public const FRESH_SECONDS = 60;

    public const TIMEOUT_SECONDS = 3;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * The last check of the relay set now, if it is under a minute old.
     *
     * @return array{ok: bool, host: string, version: ?string, ms: int, error: ?string, at: int}|null
     */
    public function recent(): ?array
    {
        $host = $this->host();
        if ($host === null) {
            return null;
        }
        $last = Cache::get(self::key($host));

        return is_array($last) && (now()->getTimestamp() - (int) ($last['at'] ?? 0)) < self::FRESH_SECONDS ? $last : null;
    }

    /** True when the relay is set and answered its health check within the last minute (asking it now if needed). */
    public function healthy(): bool
    {
        if ($this->host() === null) {
            return false;
        }

        return ($this->recent() ?? $this->check())['ok'];
    }

    /**
     * Asks the relay now and remembers the answer. With no relay set: not ok, nothing asked.
     *
     * @return array{ok: bool, host: string, version: ?string, ms: int, error: ?string, at: int}
     */
    public function check(): array
    {
        $host = $this->host();
        if ($host === null) {
            return ['ok' => false, 'host' => '', 'version' => null, 'ms' => 0, 'error' => 'No relay address is set.', 'at' => now()->getTimestamp()];
        }
        $started = microtime(true);
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::TIMEOUT_SECONDS)->acceptJson()
                ->withOptions(['allow_redirects' => false])->get("https://{$host}/healthz");
            $ok = $response->successful() && $response->json('status') === 'ok';
            $version = is_string($response->json('version')) ? mb_substr((string) $response->json('version'), 0, 40) : null;
            $error = $ok ? null : "The relay answered HTTP {$response->status()}.";
        } catch (Throwable) {
            [$ok, $version, $error] = [false, null, 'The relay did not answer.'];
        }

        return $this->record(['ok' => $ok, 'host' => $host, 'version' => $version, 'ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error, 'at' => now()->getTimestamp()]);
    }

    /**
     * Remembers [$result] as the relay's latest check (also how tests say the relay is up or down).
     *
     * @param  array{ok: bool, host?: string, version?: ?string, ms?: int, error?: ?string, at?: int}  $result
     * @return array{ok: bool, host: string, version: ?string, ms: int, error: ?string, at: int}
     */
    public function record(array $result): array
    {
        $host = $this->host() ?? '';
        $result = ['ok' => $result['ok'], 'host' => $host, 'version' => $result['version'] ?? null, 'ms' => $result['ms'] ?? 0,
            'error' => $result['error'] ?? null, 'at' => $result['at'] ?? now()->getTimestamp()];
        if ($host !== '') {
            Cache::put(self::key($host), $result, 3600);
        }

        return $result;
    }

    /** The relay host from the settings, or null when none (or not a host) is set. */
    public function host(): ?string
    {
        $url = RelayToken::url($this->settings->get('relay.url'));

        return $url === null ? null : (string) parse_url($url, PHP_URL_HOST);
    }

    private static function key(string $host): string
    {
        return 'assist.relay_health.'.sha1($host);
    }
}
