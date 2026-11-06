<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Services\AuditLog;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Failed sign-ins per email address (since v1.5): the apps' password sign-in, the Google link confirmation, the
 * account deletion check and the console's own sign-in all count here. After 5 failures in 15 minutes the address is
 * locked for the rest of that window (`429 too_many_attempts` with `Retry-After`) and the burst goes into the audit
 * log once.
 */
final class SignInGuard
{
    public const MAX_FAILURES = 5;

    public const WINDOW_SECONDS = 900;

    public function __construct(private readonly AuditLog $audit) {}

    /** Seconds until the address may try again; 0 when it is not locked. */
    public function lockedFor(string $email): int
    {
        $key = self::key($email);

        return RateLimiter::tooManyAttempts($key, self::MAX_FAILURES) ? max(1, RateLimiter::availableIn($key)) : 0;
    }

    public function assertNotLocked(string $email): void
    {
        $seconds = $this->lockedFor($email);
        if ($seconds > 0) {
            throw new ApiError(429, 'too_many_attempts', 'Too many failed sign-ins. Try again later.', [], ['Retry-After' => (string) $seconds]);
        }
    }

    public function fail(string $email, ?int $userId = null): void
    {
        $key = self::key($email);
        RateLimiter::hit($key, self::WINDOW_SECONDS);
        if (RateLimiter::attempts($key) === self::MAX_FAILURES) {
            $this->audit->record('sign_in_locked', $userId, null, 'Sign-in locked for 15 minutes after '.self::MAX_FAILURES.' failures for '.Str::limit(Str::lower(trim($email)), 120, ''));
        }
    }

    public function clear(string $email): void
    {
        RateLimiter::clear(self::key($email));
    }

    private static function key(string $email): string
    {
        return 'sign-in-failures:'.hash('sha256', Str::lower(trim($email)));
    }
}
