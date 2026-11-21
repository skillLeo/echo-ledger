<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Mail\EmailCodeMail;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\WebSessions;
use App\Support\Api\AccountRules;
use App\Support\Api\FieldErrors;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Changing the account's email from the apps (since 2.0.0-l, docs/API.md "Account and security from the apps" › 4),
 * on Filament's own email change, the one Settings › Profile uses: the console's signed confirmation link (and its
 * pending mark in the cache) goes to the new address, with a 6-digit code beside it for the app; the old address gets
 * Filament's notice with "Block" ("This wasn't me"), which ends the change and signs out everywhere. Either the link or
 * the code confirms; the change expires after 60 minutes, as Filament's link does. Only a keyed hash of the code is
 * kept, for 5 tries.
 */
final class EmailChange
{
    public const MINUTES = 60;

    public const CODE_TRIES = 5;

    public function __construct(private readonly AuditLog $audit, private readonly WebSessions $browsers, private readonly AccountSessions $sessions) {}

    /**
     * Starts a change to [$email] (the proof was checked already); answers `pending_email` and its expiry.
     *
     * @return array{pending_email: string, pending_email_expires_at: string}
     */
    public function request(User $user, mixed $email): array
    {
        $data = FieldErrors::validate(['email' => is_string($email) ? Str::lower(trim($email)) : $email],
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.email' => 'Enter a valid email address.']);
        $email = (string) $data['email'];
        if ($email === Str::lower($user->email)) {
            throw FieldErrors::fail(['email' => [['same_as_now', 'That is the address you use now.']]]);
        }
        if (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new ApiError(422, 'email_taken', 'Another account uses that address.');
        }
        $this->forget($user);

        $panel = self::panel();
        $url = $panel->getVerifyEmailChangeUrl($user, $email);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $signature = (string) ($query['signature'] ?? '');
        $expires = now()->addMinutes(self::MINUTES);
        // Filament's own pending mark: its link checks it, and "Block" removes it.
        Cache::put(self::filamentKey($signature), true, $expires);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::key($user), ['email' => $email, 'signature' => $signature, 'expires_at' => $expires->getTimestamp(),
            'hash' => self::hash($user, $code), 'tries' => 0], $expires);

        $user->notify(app(NoticeOfEmailChangeRequest::class, [
            'blockVerificationUrl' => $panel->getBlockEmailChangeVerificationUrl($user, $email, $signature),
            'newEmail' => $email,
        ]));
        Mail::to($email)->queue(new EmailCodeMail($code, 'email_change', self::MINUTES, $url));
        $this->audit->record('email_change_requested', $user->id, $user, 'Email change asked for: '.AccountRules::maskEmail($email));

        return ['pending_email' => $email, 'pending_email_expires_at' => $expires->toAtomString()];
    }

    /**
     * The change waiting for its confirmation, or null: none, expired, blocked, or confirmed by the link.
     *
     * @return array{email: string, signature: string, expires_at: int, hash: string, tries: int}|null
     */
    public function pending(User $user): ?array
    {
        $held = Cache::get(self::key($user));
        if (! is_array($held) || ! isset($held['email'], $held['signature'], $held['expires_at'], $held['hash'])) {
            return null;
        }
        if ((int) $held['expires_at'] <= now()->getTimestamp() || ! Cache::has(self::filamentKey((string) $held['signature']))
            || Str::lower($user->email) === $held['email']) {
            Cache::forget(self::key($user));

            return null;
        }

        /** @var array{email: string, signature: string, expires_at: int, hash: string, tries: int} $held */
        return $held;
    }

    /**
     * `pending_email` and `pending_email_expires_at` of GET /api/account.
     *
     * @return array{pending_email: string|null, pending_email_expires_at: string|null}
     */
    public function payload(User $user): array
    {
        $pending = $this->pending($user);

        return [
            'pending_email' => $pending['email'] ?? null,
            'pending_email_expires_at' => $pending === null ? null : Carbon::createFromTimestamp($pending['expires_at'])->toAtomString(),
        ];
    }

    /**
     * POST /api/account/email/confirm: the code. Wrong is `422 invalid_code`; the fifth wrong one ends the change, and a
     * change that ended or expired is `410 expired`. Confirmed, the address is changed and confirmed, and every browser
     * is signed out (this device's token stays).
     */
    public function confirm(User $user, #[\SensitiveParameter] mixed $code): void
    {
        $code = is_string($code) ? trim($code) : '';
        try {
            $email = Cache::lock(self::key($user).':lock', 5)->block(3, function () use ($user, $code): string {
                $pending = $this->pending($user);
                if ($pending === null) {
                    throw new ApiError(410, 'expired', 'This change has ended. Ask for it again.');
                }
                $tries = (int) $pending['tries'] + 1;
                if (! hash_equals($pending['hash'], self::hash($user, $code))) {
                    if ($tries >= self::CODE_TRIES) {
                        $this->forget($user);
                    } else {
                        Cache::put(self::key($user), [...$pending, 'tries' => $tries], Carbon::createFromTimestamp($pending['expires_at']));
                    }

                    throw new ApiError(422, 'invalid_code', 'That code is wrong. Check the email sent to your new address.');
                }
                $this->forget($user);

                return $pending['email'];
            });
        } catch (LockTimeoutException) {
            throw new ApiError(422, 'invalid_code', 'That code is wrong. Check the email sent to your new address.');
        }
        if (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new ApiError(422, 'email_taken', 'Another account uses that address.');
        }
        // As Filament's link does: the new address is confirmed by the code that reached it.
        $user->forceFill(['email' => $email])->save();
        $user->markEmailAsVerified();
        $this->browsers->revokeOthers($user->id, null);
    }

    /** DELETE /api/account/email: ends a change waiting for its confirmation; nothing to do is not an error. */
    public function cancel(User $user): void
    {
        if ($this->pending($user) !== null) {
            $this->forget($user);
            $this->audit->record('email_change_cancelled', $user->id, $user);
        }
    }

    /**
     * "Block" in the old address's notice was used (Filament's own route removed its pending mark): when it was a change
     * asked for in an app, whoever asked knew the password or a code, so every device and browser is signed out.
     */
    public function blocked(User $user, string $signature): void
    {
        $held = Cache::get(self::key($user));
        if (! is_array($held) || ! hash_equals((string) ($held['signature'] ?? ''), $signature)) {
            return;
        }
        Cache::forget(self::key($user));
        $this->audit->record('email_change_blocked', $user->id, $user, 'Email change blocked: '.AccountRules::maskEmail((string) $held['email']));
        $this->sessions->signOutEverywhere($user);
    }

    private function forget(User $user): void
    {
        $held = Cache::pull(self::key($user));
        if (is_array($held) && isset($held['signature'])) {
            Cache::forget(self::filamentKey((string) $held['signature']));
        }
    }

    private static function panel(): Panel
    {
        return Filament::getPanel('app');
    }

    private static function key(User $user): string
    {
        return 'account-email-change:'.$user->id;
    }

    private static function filamentKey(string $signature): string
    {
        return "filament:auth:email_change:verification:{$signature}";
    }

    private static function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', 'email-change|'.$user->id.'|'.$code, (string) config('app.key'));
    }
}
