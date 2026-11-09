<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Mail\EmailCodeMail;
use App\Models\EmailCode;
use App\Models\User;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sign-up and sign-in with an email code (since v1.6, P1, docs/API.md): one flow for the apps and the console. The
 * email comes first and the answer says whether it has an account (deliberately: the person must know whether they are
 * signing up; the limits make probing many addresses slow). A 6-digit code goes by email: valid 10 minutes, 5 tries,
 * a new one at most every 60 seconds and 5 an hour per address, 20 an hour per IP address; only a keyed hash is kept.
 * The right code of a new address gives a one-time sign-up token (15 minutes) for the name and the Terms; sign-ups
 * per IP address a day are limited (Settings → Sign-up, default 5).
 */
final class EmailCodes
{
    public const CODE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const PER_EMAIL_HOUR = 5;

    public const PER_IP_HOUR = 20;

    public const SIGN_UP_TOKEN_MINUTES = 15;

    public const DEFAULT_SIGN_UPS_PER_IP_DAY = 5;

    public const SIGN_IN = 'sign_in';

    public const SIGN_UP = 'sign_up';

    public function __construct(
        private readonly SignUp $signUp,
        private readonly EmailGate $gate,
        private readonly GoogleSignIn $google,
        private readonly Turnstile $turnstile,
        private readonly SettingsRepository $settings,
    ) {}

    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * POST /api/auth/start: what this address can do. An existing account lists its ways in; a new address may sign up
     * only while sign-up is open to it (`403 sign_up_closed`) and is not a throwaway or outside the allowed domains
     * (`422 email_not_allowed`).
     *
     * @return array{account: string, methods: list<string>, sign_up: bool}
     */
    public function start(string $email): array
    {
        $email = self::normalize($email);
        $user = User::query()->where('email', $email)->first();
        $google = $this->google->configured();
        if ($user instanceof User) {
            return [
                'account' => 'existing',
                'methods' => array_keys(array_filter(['password' => $user->has_password, 'code' => true, 'google' => $google])),
                'sign_up' => $this->signUp->mode() === SignUp::ANYONE,
            ];
        }
        $this->assertMaySignUp($email);

        return ['account' => 'new', 'methods' => $google ? ['code', 'google'] : ['code'], 'sign_up' => true];
    }

    /** `403 sign_up_closed` or `422 email_not_allowed` unless [$email] may make an account now. */
    public function assertMaySignUp(string $email): void
    {
        if (! $this->signUp->allows($email)) {
            throw new ApiError(403, 'sign_up_closed', 'Sign-up is closed on this server. Ask the owner for an invitation.');
        }
        if (! $this->gate->allows($email)) {
            throw new ApiError(422, 'email_not_allowed', 'This email address cannot be used to sign up. Use your usual email address.');
        }
    }

    /**
     * POST /api/auth/code: a new code by email (it replaces the last one). The server decides the purpose from the
     * address: an account signs in, a new address signs up (and passes the sign-up checks). Turnstile is checked when the
     * web sign-up sends its answer, or when [$requireTurnstile] (the console's sign-up page) and it is set.
     *
     * @return array{resend_after: int, expires_in: int}
     */
    public function send(string $email, ?string $turnstileToken, ?string $ip, bool $requireTurnstile = false): array
    {
        $email = self::normalize($email);
        $exists = User::query()->where('email', $email)->exists();
        if (! $exists) {
            $this->assertMaySignUp($email);
            if (($requireTurnstile || filled($turnstileToken)) && ! $this->turnstile->verify($turnstileToken, $ip)) {
                throw new ApiError(422, 'turnstile_failed', 'The check that you are not a robot did not pass. Try it again.');
            }
            $this->assertSignUpQuota($ip);
        }

        return DB::transaction(function () use ($email, $exists, $ip): array {
            $last = EmailCode::query()->where('email', $email)->latest('id')->lockForUpdate()->first();
            if ($last instanceof EmailCode && $last->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
                $wait = max(1, self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now()));

                throw new ApiError(429, 'resend_too_soon', "Wait {$wait} seconds before asking for a new code.", ['resend_after' => $wait], ['Retry-After' => (string) $wait]);
            }
            $hourAgo = now()->subHour();
            $byEmail = EmailCode::query()->where('email', $email)->where('created_at', '>', $hourAgo)->oldest('id')->get(['created_at']);
            $byIp = $ip === null ? collect() : EmailCode::query()->where('ip', $ip)->where('created_at', '>', $hourAgo)->oldest('id')->get(['created_at']);
            foreach ([[$byEmail, self::PER_EMAIL_HOUR], [$byIp, self::PER_IP_HOUR]] as [$rows, $max]) {
                if ($rows->count() >= $max) {
                    $wait = max(1, (int) now()->diffInSeconds($rows->first()->created_at->copy()->addHour()));

                    throw new ApiError(429, 'too_many_attempts', 'Too many codes asked for. Try again later.', [], ['Retry-After' => (string) $wait]);
                }
            }
            // A new code replaces the old one.
            EmailCode::query()->where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            EmailCode::query()->create([
                'email' => $email,
                'purpose' => $exists ? self::SIGN_IN : self::SIGN_UP,
                'code_hash' => self::hash($email, $code),
                'expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'ip' => $ip,
            ]);
            Mail::to($email)->queue(new EmailCodeMail($code, $exists ? self::SIGN_IN : self::SIGN_UP, self::CODE_MINUTES));

            return ['resend_after' => self::RESEND_SECONDS, 'expires_in' => self::CODE_MINUTES * 60];
        });
    }

    /**
     * The code of [$email] is right: the code row, still unused (signing in uses it up afterwards, so a two-step account
     * can send the same code again with its authenticator code). A wrong one is `422 invalid_code` with `attempts_left`;
     * after 5 wrong ones, or 10 minutes, `422 code_expired`.
     */
    public function check(string $email, string $code): EmailCode
    {
        $email = self::normalize($email);
        $code = (string) preg_replace('/\D/', '', $code);

        // The wrong try is counted in the transaction and the answer given after it, so the count is kept.
        [$row, $left] = DB::transaction(function () use ($email, $code): array {
            $row = EmailCode::query()->where('email', $email)->whereNull('used_at')->latest('id')->lockForUpdate()->first();
            if (! $row instanceof EmailCode || $row->expires_at->isPast() || $row->attempts >= self::MAX_ATTEMPTS) {
                return [null, null];
            }
            if (strlen($code) !== 6 || ! hash_equals($row->code_hash, self::hash($email, $code))) {
                $row->increment('attempts');

                return [null, max(0, self::MAX_ATTEMPTS - $row->attempts)];
            }

            return [$row, null];
        });
        if ($row instanceof EmailCode) {
            return $row;
        }
        if ($left === null) {
            throw new ApiError(422, 'code_expired', 'This code has expired. Ask for a new one.');
        }

        throw new ApiError(422, 'invalid_code', $left > 0 ? "That code is not right. {$left} ".($left === 1 ? 'try' : 'tries').' left.' : 'That code is not right. Ask for a new one.', ['attempts_left' => $left]);
    }

    public function useUp(EmailCode $row): void
    {
        $row->forceFill(['used_at' => now()])->save();
    }

    /** The right code of a new address: it is used up and gives the one-time sign-up token (15 minutes). */
    public function signUpToken(EmailCode $row): string
    {
        $token = Str::random(48);
        $row->forceFill([
            'used_at' => now(),
            'verified_at' => now(),
            'sign_up_token_hash' => hash('sha256', $token),
            'sign_up_token_expires_at' => now()->addMinutes(self::SIGN_UP_TOKEN_MINUTES),
        ])->save();

        return $token;
    }

    /**
     * The address a sign-up token proved, using the token up; `422 invalid_code` for a used or old one. Runs inside the
     * caller's transaction so the token is spent only when the account is made.
     */
    public function redeemSignUpToken(string $token): string
    {
        $row = EmailCode::query()->where('sign_up_token_hash', hash('sha256', $token))->lockForUpdate()->first();
        if (! $row instanceof EmailCode || $row->sign_up_token_expires_at === null || $row->sign_up_token_expires_at->isPast()) {
            throw new ApiError(422, 'invalid_code', 'This sign-up has expired. Start again with your email.');
        }
        $row->forceFill(['sign_up_token_hash' => null])->save();

        return $row->email;
    }

    /** Sign-ups a day per IP address (Settings → Sign-up, default 5). */
    public function signUpsPerIpDay(): int
    {
        $value = $this->settings->get('signup.per_ip_day', self::DEFAULT_SIGN_UPS_PER_IP_DAY);

        return is_numeric($value) ? max(1, min(1000, (int) $value)) : self::DEFAULT_SIGN_UPS_PER_IP_DAY;
    }

    /** `429 sign_up_limit` once [$ip] made today's sign-ups. */
    public function assertSignUpQuota(?string $ip): void
    {
        if ($ip === null) {
            return;
        }
        $key = self::quotaKey($ip);
        if (RateLimiter::tooManyAttempts($key, $this->signUpsPerIpDay())) {
            $wait = max(1, RateLimiter::availableIn($key));

            throw new ApiError(429, 'sign_up_limit', 'Too many new accounts from this network today. Try again tomorrow.', [], ['Retry-After' => (string) $wait]);
        }
    }

    /** One more account made from [$ip] today. */
    public function countSignUp(?string $ip): void
    {
        if ($ip !== null) {
            RateLimiter::hit(self::quotaKey($ip), 86400);
        }
    }

    private static function quotaKey(string $ip): string
    {
        return 'sign-ups-per-ip:'.hash('sha256', $ip);
    }

    /** A keyed hash: a copy of the table alone does not give the codes back. */
    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }
}
