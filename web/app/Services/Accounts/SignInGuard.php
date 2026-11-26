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

    /**
     * Counts a try before it is checked (since 2.0.0-l, account proofs): the increment is atomic, so tries sent at the
     * same moment cannot all pass a check made before any of them failed. Refuses the try past the fifth with
     * `429 too_many_attempts`. A right try then clear()s the count; a wrong one calls claimedFailed().
     */
    public function claim(string $email): void
    {
        $key = self::key($email);
        if (RateLimiter::hit($key, self::WINDOW_SECONDS) > self::MAX_FAILURES) {
            throw new ApiError(429, 'too_many_attempts', 'Too many failed sign-ins. Try again later.', [], ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
        }
    }

    /** A claimed try that was wrong: already counted, so only the burst goes to the audit log (once). */
    public function claimedFailed(string $email, ?int $userId = null): void
    {
        if (RateLimiter::attempts(self::key($email)) === self::MAX_FAILURES) {
            $this->audit->record('sign_in_locked', $userId, null, 'Sign-in locked for 15 minutes after '.self::MAX_FAILURES.' failures for '.Str::limit(Str::lower(trim($email)), 120, ''));
        }
    }

    /** A claimed try that guessed nothing (no code was sent): it does not count. */
    public function release(string $email): void
    {
        $key = self::key($email);
        if (RateLimiter::attempts($key) > 0) {
            RateLimiter::decrement($key, self::WINDOW_SECONDS);
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
