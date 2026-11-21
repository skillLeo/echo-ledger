<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Mail\EmailCodeMail;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * "Proof" for a change to the account from the apps (since 2.0.0-l, docs/API.md "Account and security from the apps"),
 * the same as `DELETE /api/account`: the password, or an authenticator or recovery code; an account with neither (made
 * with Google) uses a 6-digit code sent to its current address (POST /api/account/proof-code). Wrong proofs count in
 * SignInGuard like failed sign-ins: 5 in 15 minutes lock the address (`429 too_many_attempts`). Nothing about a code
 * or password is logged; only a keyed hash of an email code is kept, for 10 minutes and 5 tries.
 */
final class AccountProof
{
    public const CODE_MINUTES = 10;

    public const CODE_TRIES = 5;

    public function __construct(private readonly SignInGuard $guard, private readonly TwoFactorCodes $codes) {}

    /** Whether the account can prove itself only by an email code (no password it knows, no two-step). */
    public static function needsEmailCode(User $user): bool
    {
        return ! self::hasPassword($user) && ! $user->hasTwoFactor();
    }

    /** Whether the person chose a password (since v1.6 an account made with a code or Google has none). */
    public static function hasPassword(User $user): bool
    {
        return $user->has_password;
    }

    /**
     * Checks one proof from [$input] (`password`, `otp` or `email_code`) or throws. With two-step on, the proof must
     * include an authenticator or recovery code: the password alone is never enough, or the password and a device
     * would stand in for the second step (a password sent with it is checked too). [$authenticatorOnly]: only an
     * authenticator or recovery code counts, whatever the account has (turning two-step off).
     *
     * @param  array<string, mixed>  $input
     */
    public function check(User $user, #[\SensitiveParameter] array $input, bool $authenticatorOnly = false): void
    {
        $this->guard->assertNotLocked($user->email);
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $otp = is_string($input['otp'] ?? null) ? $input['otp'] : '';
        $emailCode = is_string($input['email_code'] ?? null) ? $input['email_code'] : '';
        if ($password === '' && $otp === '' && $emailCode === '') {
            $this->required($user);
        }
        // Counted before it is checked (atomic): proofs sent at the same moment cannot pass a count made before any of
        // them failed. A right proof clears the count, one that guessed nothing gives its try back.
        $this->guard->claim($user->email);

        if ($authenticatorOnly || $user->hasTwoFactor()) {
            if ($password !== '' && ! $this->passwordMatches($user, $password)) {
                $this->fail($user, 'wrong_password', 'That password is wrong.');
            }
            if ($otp === '') {
                // Nothing was guessed: not counted as a failure.
                $this->guard->release($user->email);

                throw new ApiError(422, 'invalid_otp', 'Enter a code from your authenticator app, or a recovery code.', ['accepts' => ['otp']]);
            }
            if (! $this->codes->verify($user, $otp)) {
                $this->fail($user, 'invalid_otp', 'That code is wrong or was used already.');
            }
        } elseif ($otp !== '') {
            // No two-step: no authenticator code can be right.
            $this->fail($user, 'invalid_otp', 'That code is wrong or was used already.');
        } elseif ($password !== '') {
            if (! $this->passwordMatches($user, $password)) {
                $this->fail($user, 'wrong_password', 'That password is wrong.');
            }
        } elseif ($emailCode !== '') {
            // Only for an account that has nothing else to prove itself with: otherwise the email alone would stand in
            // for the password or two-step.
            if (! self::needsEmailCode($user) || ! $this->useCode($user, $emailCode)) {
                $this->fail($user, 'invalid_code', 'That code is wrong or has expired. Ask for a new one.');
            }
        }
        $this->guard->clear($user->email);
    }

    private function passwordMatches(User $user, #[\SensitiveParameter] string $password): bool
    {
        return self::hasPassword($user) && Hash::check($password, (string) $user->password);
    }

    private function required(User $user): never
    {
        throw new ApiError(422, 'proof_required', 'Confirm it is you: your password, a code from your authenticator app, or a code sent to your email.',
            ['accepts' => self::accepts($user)]);
    }

    /** Sends a 6-digit code to the account's address (`POST /api/account/proof-code`; 5 an hour, by the route). */
    public function sendCode(User $user, string $purpose = 'confirm'): void
    {
        if ($purpose === 'confirm' && ! self::needsEmailCode($user)) {
            throw new ApiError(409, 'proof_code_not_needed', 'Confirm with your password or a code from your authenticator app.', ['accepts' => self::accepts($user)]);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::key($user), ['hash' => self::hash($user, $code), 'tries' => 0], now()->addMinutes(self::CODE_MINUTES));
        Mail::to($user->email)->queue((new EmailCodeMail($code, $purpose, self::CODE_MINUTES))->onConnection('sync'));
    }

    /** @return list<string> the proofs this account can give */
    public static function accepts(User $user): array
    {
        // With two-step on, an authenticator or recovery code is needed (a password may come with it).
        if ($user->hasTwoFactor()) {
            return ['otp'];
        }

        return array_values(array_filter([
            self::hasPassword($user) ? 'password' : null,
            self::needsEmailCode($user) ? 'email_code' : null,
        ]));
    }

    /**
     * Uses up the account's code when [$code] is it. Under a lock, and the try is counted before the comparison, so
     * parallel guesses cannot get more than CODE_TRIES tries out of one code.
     */
    private function useCode(User $user, string $code): bool
    {
        $key = self::key($user);

        try {
            return (bool) Cache::lock($key.':lock', 5)->block(3, function () use ($user, $key, $code): bool {
                $held = Cache::get($key);
                if (! is_array($held) || (int) $held['tries'] >= self::CODE_TRIES) {
                    Cache::forget($key);

                    return false;
                }
                Cache::put($key, ['hash' => $held['hash'], 'tries' => (int) $held['tries'] + 1], now()->addMinutes(self::CODE_MINUTES));
                if (! hash_equals((string) $held['hash'], self::hash($user, trim($code)))) {
                    return false;
                }
                Cache::forget($key);

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function fail(User $user, string $code, string $message): never
    {
        $this->guard->claimedFailed($user->email, $user->id);

        throw new ApiError(422, $code, $message);
    }

    private static function key(User $user): string
    {
        return 'account-proof:'.$user->id;
    }

    private static function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$code, (string) config('app.key'));
    }
}
