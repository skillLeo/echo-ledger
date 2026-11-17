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

        return $this->record(['host' => $host, ...self::probe($host)]);
    }

    /**
     * One health check of [$host], guarded against reaching anything inside the server's own network: the host is
     * resolved here, refused when any of its addresses is private, loopback, link-local or reserved, and the request
     * goes to the checked address only (pinned, no redirects).
     *
     * @return array{ok: bool, version: ?string, ms: int, error: ?string, at: int}
     */
    public static function probe(string $host): array
    {
        $started = microtime(true);
        $done = fn (bool $ok, ?string $version, ?string $error): array => [
            'ok' => $ok, 'version' => $version, 'ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error, 'at' => now()->getTimestamp(),
        ];
        $addresses = self::resolve($host);
        if ($addresses === []) {
            return $done(false, null, 'The relay\'s address could not be found.');
        }
        foreach ($addresses as $ip) {
            if (! self::isPublic($ip)) {
                return $done(false, null, 'The relay\'s address points inside a private network; it must be a public server.');
            }
        }
        $ip = $addresses[0];
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::TIMEOUT_SECONDS)->acceptJson()
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:443:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]]])
                ->get("https://{$host}/healthz");
            $ok = $response->status() === 200 && $response->json('status') === 'ok';
            $version = is_string($response->json('version')) ? mb_substr((string) $response->json('version'), 0, 40) : null;

            return $done($ok, $version, $ok ? null : "The relay answered HTTP {$response->status()}.");
        } catch (Throwable) {
            return $done(false, null, 'The relay did not answer.');
        }
    }

    /** Resolves [$host] to its IPv4 and IPv6 addresses (tests replace this with $resolveUsing). */
    public static ?\Closure $resolveUsing = null;

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (self::$resolveUsing !== null) {
            return array_values((self::$resolveUsing)($host));
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }

    /** True for a public unicast address: not private, loopback, link-local, unspecified or otherwise reserved. */
    public static function isPublic(string $ip): bool
    {
        // An IPv4 address carried in IPv6 is judged as that IPv4 address: mapped (::ffff:10.0.0.1), NAT64
        // (64:ff9b::/96, 64:ff9b::a00:1 or 64:ff9b::10.0.0.1) and 6to4 (2002::/16, the next 32 bits: 2002:0a00:0001::).
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        } elseif (($embedded = self::embeddedIpv4($ip)) !== null) {
            $ip = $embedded;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|f[cd][0-9a-f]{2}:|fe[89ab][0-9a-f]:)/i', $ip) !== 1;
    }

    /**
     * The IPv4 address an IPv6 address carries, else null: mapped (::ffff:0:0/96, in any spelling, the hex form
     * ::ffff:a00:1 too), NAT64 (64:ff9b::/96 and the local-use 64:ff9b:1::/48, its last 32 bits), 6to4 (2002::/16, the
     * next 32 bits) and Teredo (2001::/32: the client's address is the last 32 bits, inverted).
     */
    private static function embeddedIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $bytes = inet_pton($ip);
        if ($bytes === false || strlen($bytes) !== 16) {
            return null;
        }
        $v4 = fn (string $four): string => (string) inet_ntop($four);

        return match (true) {
            substr($bytes, 0, 12) === str_repeat("\x00", 10)."\xff\xff" => $v4(substr($bytes, 12, 4)),
            substr($bytes, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\x00", 8) => $v4(substr($bytes, 12, 4)),
            substr($bytes, 0, 6) === "\x00\x64\xff\x9b\x00\x01" => $v4(substr($bytes, 12, 4)),
            substr($bytes, 0, 2) === "\x20\x02" => $v4(substr($bytes, 2, 4)),
            substr($bytes, 0, 4) === "\x20\x01\x00\x00" => $v4(substr($bytes, 12, 4) ^ "\xff\xff\xff\xff"),
            default => null,
        };
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
