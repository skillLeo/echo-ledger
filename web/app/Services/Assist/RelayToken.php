<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Exceptions\ApiError;
use App\Models\AssistSession;
use App\Services\SettingsRepository;
use Illuminate\Support\Str;

/**
 * The relay block of a Live Assist session (docs/API_ASSIST.md "Relay token"): the relay's address and a compact
 * HS256 token for this session, at most 15 minutes, signed with the current `assist_relay` secret. What the relay
 * checks is in services/assist-relay/src/auth.rs.
 */
final class RelayToken
{
    public const ISSUER = 'echoledger';

    public const AUDIENCE = 'assist-relay';

    public const PATH = '/v1/assist';

    public function __construct(private readonly RelaySecret $secret, private readonly AssistPlans $plans, private readonly SettingsRepository $settings) {}

    /**
     * `{"url", "token", "expires_at"}` for [$session].
     *
     * @return array{url: string, token: string, expires_at: string}
     */
    public function block(AssistSession $session): array
    {
        $url = self::url($this->settings->get('relay.url'));
        $key = $this->secret->current();
        if ($url === null || $key === null) {
            throw new ApiError(503, 'provider_not_configured', 'Live Assist is not set up on the server yet.');
        }
        $now = now()->getTimestamp();
        $expires = $now + min(900, (int) config('assist.token_ttl_seconds', 900));
        $token = self::sign($this->claims($session, $now, $expires), $key);

        return ['url' => $url, 'token' => $token, 'expires_at' => now()->setTimestamp($expires)->toAtomString()];
    }

    /**
     * The token's claims.
     *
     * @return array<string, mixed>
     */
    public function claims(AssistSession $session, int $issuedAt, int $expiresAt): array
    {
        $plan = $this->plans->for($session->workspace_id);
        $modes = $this->plans->modes($session->workspace_id, $session->practice);

        return [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => (string) $session->user_id,
            'ws' => (string) $session->workspace_id,
            'sid' => $session->id,
            'plan' => $plan['claim'],
            'modes' => $modes,
            'models' => self::models($modes),
            // Practice's own allowance for a Practice session (1.9.0-h), the live one otherwise.
            'budget' => $this->plans->left($session->workspace_id, $session->user_id, $session->practice),
            'practice' => $session->practice,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => (string) Str::ulid(),
        ];
    }

    /**
     * Every model the relay may use for [$modes]: each one's primary, fallback and upgrade.
     *
     * @param  list<string>  $modes
     * @return list<string>
     */
    public static function models(array $modes): array
    {
        $models = [];
        foreach ($modes as $mode) {
            $plan = (array) config("assist.modes.{$mode}", []);
            foreach (['primary', 'fallback', 'upgrade'] as $role) {
                if (is_string($plan[$role] ?? null)) {
                    $models[] = $plan[$role];
                }
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * A compact JWS: base64url(header).base64url(claims).base64url(HMAC-SHA256).
     *
     * @param  array<string, mixed>  $claims
     */
    public static function sign(array $claims, string $key): string
    {
        $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $signed = $encode('{"alg":"HS256","typ":"JWT"}').'.'.$encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $signed.'.'.$encode(hash_hmac('sha256', $signed, $key, true));
    }

    /**
     * `wss://<relay host>/v1/assist` from the `relay.url` setting (a host, or a URL whose host is taken); null when it is
     * not set or not a host. Never from the request.
     */
    public static function url(mixed $setting): ?string
    {
        if (! is_string($setting) || trim($setting) === '') {
            return null;
        }
        $value = trim($setting);
        $host = parse_url(str_contains($value, '://') ? $value : 'wss://'.$value, PHP_URL_HOST);
        if (! is_string($host) || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) !== 1) {
            return null;
        }

        return 'wss://'.strtolower($host).self::PATH;
    }
}
