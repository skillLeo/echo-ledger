<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Models\Credential;
use App\Services\CredentialVault;
use Illuminate\Support\Carbon;

/**
 * The secret the server shares with the Live Assist relay (docs/API_ASSIST.md, API Credentials → `assist_relay`): it
 * signs the relay tokens (HS256) and the relay signs its internal requests with it (HMAC-SHA256). Generating a new one
 * keeps the old one as `previous_secret` for 15 minutes, so the relay can be given the new one without a gap: during
 * that time the server accepts either on the internal API, and the relay accepts tokens signed with either.
 */
final class RelaySecret
{
    public const PROVIDER = 'assist_relay';

    public const CURRENT = 'secret';

    public const PREVIOUS = 'previous_secret';

    /** The relay refuses a shorter secret (services/assist-relay/src/config.rs). */
    public const MIN_LENGTH = 32;

    public function __construct(private readonly CredentialVault $vault) {}

    public function current(): ?string
    {
        return $this->vault->get(self::PROVIDER, self::CURRENT);
    }

    /** The rotated-out secret, while its 15 minutes last. */
    public function previous(): ?string
    {
        $previous = $this->vault->get(self::PROVIDER, self::PREVIOUS);
        if ($previous === null) {
            return null;
        }
        $since = Credential::query()->where(['provider' => self::PROVIDER, 'key' => self::PREVIOUS])->value('updated_at');
        $overlap = (int) config('assist.secret_overlap_seconds', 900);

        return $since !== null && Carbon::parse($since)->addSeconds($overlap)->isFuture() ? $previous : null;
    }

    /**
     * Every secret the server accepts now: the current one, then the previous one during the overlap.
     *
     * @return list<string>
     */
    public function accepted(): array
    {
        return array_values(array_filter([$this->current(), $this->previous()], fn (?string $s): bool => $s !== null));
    }

    /** Makes [$secret] (a new random one when null) the current secret; the old one stays accepted for 15 minutes. */
    public function rotate(?string $secret = null, ?int $userId = null): string
    {
        $secret ??= CredentialVault::generateSecret();
        $old = $this->current();
        if ($old !== null && ! hash_equals($old, $secret)) {
            $this->vault->put(self::PROVIDER, self::PREVIOUS, $old, $userId);
            // The overlap counts from now even when the previous slot held the same value already.
            Credential::query()->where(['provider' => self::PROVIDER, 'key' => self::PREVIOUS])->update(['updated_at' => now()]);
        }
        $this->vault->put(self::PROVIDER, self::CURRENT, $secret, $userId);

        return $secret;
    }

    /** Whether [$signature] (lowercase hex) is the HMAC of `timestamp\nMETHOD\npath\nbody` with an accepted secret. */
    public function verifies(string $timestamp, string $method, string $path, string $body, string $signature): bool
    {
        $message = $timestamp."\n".strtoupper($method)."\n".$path."\n".$body;
        foreach ($this->accepted() as $secret) {
            if (hash_equals(hash_hmac('sha256', $message, $secret), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }
}
