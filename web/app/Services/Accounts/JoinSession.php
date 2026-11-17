<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The invite link a visitor opened (v1.8 P6.3), kept in their session, encrypted, for an hour: signing in, the sign-up
 * page and "Continue with Google" carry on with it without its token in any address after the first (no referrer, no
 * log line with it). Forgotten once used.
 */
final class JoinSession
{
    private const KEY = 'join.link';

    private const MINUTES = 60;

    public static function put(string $token): void
    {
        if ($token !== '' && strlen($token) <= 128 && app()->bound('session')) {
            session()->put(self::KEY, Crypt::encryptString((string) json_encode(['t' => $token, 'at' => now()->getTimestamp()])));
        }
    }

    public static function token(): ?string
    {
        $stored = app()->bound('session') ? session()->get(self::KEY) : null;
        if (! is_string($stored)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($stored), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($data) || ! is_string($data['t'] ?? null) || (int) ($data['at'] ?? 0) < now()->subMinutes(self::MINUTES)->getTimestamp()) {
            return null;
        }

        return $data['t'];
    }

    public static function forget(): void
    {
        if (! app()->bound('session')) {
            return;
        }
        session()->forget(self::KEY);
    }
}
