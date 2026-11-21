<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Mail\EmailChangedMail;
use App\Mail\EmailCodeMail;
use App\Mail\EmailRestoreNoticeMail;
use App\Models\AuditEvent;
use App\Models\Passkey;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\WebSessions;
use App\Support\Api\AccountRules;
use App\Support\Api\FieldErrors;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

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

    /** Days the old address's "put it back" link works. */
    public const RESTORE_DAYS = 7;

    public function __construct(private readonly AuditLog $audit, private readonly WebSessions $browsers, private readonly AccountSessions $sessions) {}

    /**
     * Starts a change to [$email] (the proof was checked already); answers `pending_email` and its expiry. [$byTwoStep]:
     * the proof was a two-step code of the account (on before the change): then the old address is told, without a
     * restore link (tellOldAddress()).
     *
     * @return array{pending_email: string, pending_email_expires_at: string}
     */
    public function request(User $user, mixed $email, bool $byTwoStep = false): array
    {
        $this->assertNotLocked($user);
        $email = $this->validated($user, $email);
        if (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists() || self::isReserved($email, $user->id)) {
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
            'hash' => self::hash($user, $code), 'tries' => 0, 'two_step' => $byTwoStep], $expires);

        $user->notify(app(NoticeOfEmailChangeRequest::class, [
            'blockVerificationUrl' => $panel->getBlockEmailChangeVerificationUrl($user, $email, $signature),
            'newEmail' => $email,
        ]));
        Mail::to($email)->queue(new EmailCodeMail($code, 'email_change', self::MINUTES, $url));
        $this->audit->record('email_change_requested', $user->id, $user, 'Email change asked for: '.AccountRules::maskEmail($email));

        return ['pending_email' => $email, 'pending_email_expires_at' => $expires->toAtomString()];
    }

    /**
     * The new address checked as an address (before any proof is asked): `422 validation_failed` with `invalid_email`,
     * `required`, `too_long` or `same_as_now`. Whether another account has it is said only after the proof
     * (`email_taken`), so the check tells nobody without it which addresses have accounts.
     */
    public function validated(User $user, mixed $email): string
    {
        $data = FieldErrors::validate(['email' => is_string($email) ? Str::lower(trim($email)) : $email],
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.email' => 'Enter a valid email address.']);
        $email = (string) $data['email'];
        if ($email === Str::lower($user->email)) {
            throw FieldErrors::fail(['email' => [['same_as_now', 'That is the address you use now.']]]);
        }

        return $email;
    }

    /**
     * The change waiting for its confirmation, or null: none, expired, blocked, or confirmed by the link.
     *
     * @return array{email: string, signature: string, expires_at: int, hash: string, tries: int, two_step?: bool}|null
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

        /** @var array{email: string, signature: string, expires_at: int, hash: string, tries: int, two_step?: bool} $held */
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
            [$email, $byTwoStep] = Cache::lock(self::key($user).':lock', 5)->block(3, function () use ($user, $code): array {
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

                return [$pending['email'], (bool) ($pending['two_step'] ?? false)];
            });
        } catch (LockTimeoutException) {
            throw new ApiError(422, 'invalid_code', 'That code is wrong. Check the email sent to your new address.');
        }
        if (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists() || self::isReserved($email, $user->id)) {
            throw new ApiError(422, 'email_taken', 'Another account uses that address.');
        }
        $old = $user->email;
        // As Filament's link does: the new address is confirmed by the code that reached it.
        $user->forceFill(['email' => $email, 'remember_token' => Str::random(60)])->save();
        $user->markEmailAsVerified();
        $this->browsers->revokeOthers($user->id, null);
        $this->tellOldAddress($user, $old, $byTwoStep);
    }

    /**
     * After a change (by an app's code or the console's link). The address before it hears of it, and so does the new
     * one. Unless the change was proven with the account's two-step code ([$byTwoStep]: on before the change, so the old
     * mailbox alone could not have made it), a 7-day restore window opens, or stays as it is when one is open already:
     * anchored to the address before the FIRST change, which stays reserved (nobody can be given it) and gets the
     * "Put my address back" link that needs no sign-in (restore()). Proven with two-step, the old address is told to
     * contact support instead, so a mailbox someone else reads cannot take the account back.
     */
    public function tellOldAddress(User $user, string $old, bool $byTwoStep = false): void
    {
        $window = self::window($user);
        if ($window === null && $byTwoStep) {
            Mail::to($old)->queue(new EmailChangedMail(AccountRules::maskEmail($user->email), null, self::RESTORE_DAYS));

            return;
        }
        if ($window === null) {
            $window = ['to' => $old, 'changed_at' => now()->getTimestamp(), 'until' => now()->addDays(self::RESTORE_DAYS)->getTimestamp(), 'nonce' => Str::random(40)];
            $user->forceFill(['email_restore' => $window, 'email_restore_address' => Str::lower(trim($old)),
                'email_restore_until' => Carbon::createFromTimestamp($window['until'])])->saveQuietly();
        }
        $until = Carbon::createFromTimestamp($window['until']);
        $url = URL::temporarySignedRoute('account.email.restore', $until, ['user' => $user->id, 'n' => $window['nonce']]);
        Mail::to($window['to'])->queue(new EmailChangedMail(AccountRules::maskEmail($user->email), $url, self::RESTORE_DAYS));
        Mail::to($user->email)->queue(new EmailRestoreNoticeMail(EmailRestoreNoticeMail::LINK_SENT, AccountRules::maskEmail($window['to'])));
    }

    /**
     * The open restore window of the person's address, or null (none, used, or its 7 days are over).
     *
     * @return array{to: string, changed_at: int, until: int, nonce: string}|null
     */
    public static function window(User $user): ?array
    {
        $window = $user->email_restore;
        if (! is_array($window) || isset($window['restored_at']) || ! is_string($window['to'] ?? null) || ! is_string($window['nonce'] ?? null)
            || (int) ($window['until'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return ['to' => $window['to'], 'changed_at' => (int) ($window['changed_at'] ?? 0), 'until' => (int) $window['until'], 'nonce' => $window['nonce']];
    }

    /**
     * Whether [$email] is reserved for another account's open restore window ([$exceptUserId]'s own does not count).
     * Compared by the database with `=` on a column of users.email's own collation, so an address the database takes
     * for the same one (another case, an accented lookalike) is reserved too. A schema without the column errors: it
     * never lets an address through.
     */
    public static function isReserved(string $email, ?int $exceptUserId = null): bool
    {
        // SQLite would read an unknown column's quoted name as a string and answer "not reserved": ask first.
        if (! Schema::hasColumn('users', 'email_restore_address')) {
            throw new RuntimeException('users.email_restore_address is missing: run the migrations of 2.0.0-l.');
        }

        return User::query()->where('email_restore_address', Str::lower(trim($email)))->where('email_restore_until', '>', now())
            ->when($exceptUserId !== null, fn ($q) => $q->whereKeyNot($exceptUserId))->exists();
    }

    /**
     * Whether a change proven with [$user]'s two-step code may skip the restore link: two-step has been on for at least
     * RESTORE_DAYS (since its last mfa_enabled, which every path writes: AuditLog's listener) and its recovery codes
     * were last made at least RESTORE_DAYS ago (users.recovery_codes_generated_at, set on every path by the model), so
     * a code is not one whoever changed the address could have set up.
     */
    public static function twoStepIsOld(User $user): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        $enabled = AuditEvent::query()->where('user_id', $user->id)->where('event', 'mfa_enabled')->latest('id')->first();
        if (! $enabled instanceof AuditEvent || $enabled->created_at->gt(now()->subDays(self::RESTORE_DAYS))) {
            return false;
        }

        $codes = $user->recovery_codes_generated_at;

        return $codes === null || $codes->lte(now()->subDays(self::RESTORE_DAYS));
    }

    /**
     * `409 email_change_locked {"until"}`: the apps cannot change the address while its restore window is open, nor
     * after a restore until the new password is chosen or 24 hours have passed, whichever is later.
     */
    public function assertNotLocked(User $user): void
    {
        $window = self::window($user);
        $until = null;
        if ($window !== null) {
            $until = Carbon::createFromTimestamp($window['until']);
        } elseif (is_array($user->email_restore) && isset($user->email_restore['restored_at'])) {
            $lockUntil = Carbon::createFromTimestamp((int) ($user->email_restore['lock_until'] ?? 0));
            if (($user->email_restore['awaiting_reset'] ?? false) === true || $lockUntil->isFuture()) {
                $until = $lockUntil;
            }
        }
        if ($until !== null) {
            throw new ApiError(409, 'email_change_locked', 'Your email address was changed or put back recently. It can be changed again after '
                .$until->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, Y').'.', ['until' => $until->toAtomString()]);
        }
    }

    /**
     * The new password after a restore was chosen (Laravel's PasswordReset), or the owner signed in again after it (an
     * email code, Google, a passkey): the lock ends at its 24 hours.
     */
    public static function resetCompleted(User $user): void
    {
        if (is_array($user->email_restore) && ($user->email_restore['awaiting_reset'] ?? false) === true) {
            $user->forceFill(['email_restore' => [...$user->email_restore, 'awaiting_reset' => false]])->saveQuietly();
        }
    }

    /**
     * The original address's link was used ([$nonce] is the open window's; once, under a lock of the account's row):
     * the address goes back to it, confirmed, and every way in is reset, as anything may be the changer's: the password
     * stops working (a reset link goes to the address), every passkey, the Google link and two-step go, and every
     * device, browser, code and connected app is signed out. The owner secures the account again from the reset. The
     * address the account had is told. Returns whether it was put back.
     */
    public function restore(User $user, string $nonce): bool
    {
        $done = DB::transaction(function () use ($user, $nonce): ?array {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $window = $fresh instanceof User ? self::window($fresh) : null;
            // An account with the address made before the change blocks it (it was not reserved then); one made after it
            // can only be a lookalike that slipped past the reservation, and does not.
            if (! $fresh instanceof User || $window === null || ! hash_equals($window['nonce'], $nonce)
                || User::query()->whereKeyNot($user->id)->where('email', $window['to'])->where('created_at', '<=', Carbon::createFromTimestamp($window['changed_at']))->exists()) {
                return null;
            }
            $was = $fresh->email;
            $fresh->forceFill([
                'email' => $window['to'], 'password' => Str::password(64), 'has_password' => false, 'google_id' => null,
                'app_authentication_secret' => null, 'app_authentication_recovery_codes' => null,
                'email_restore' => ['to' => $window['to'], 'restored_at' => now()->getTimestamp(), 'lock_until' => now()->addDay()->getTimestamp(), 'awaiting_reset' => true],
                'email_restore_address' => null, 'email_restore_until' => null,
            ])->save();
            $fresh->markEmailAsVerified();
            Passkey::query()->where('user_id', $fresh->id)->delete();

            return [$fresh, $window, $was];
        });
        if ($done === null) {
            return false;
        }
        [$fresh, $window, $was] = $done;
        $this->forget($fresh);
        $this->sessions->signOutEverywhere($fresh);
        $broker = Password::broker();
        if ($broker instanceof PasswordBroker) {
            $fresh->sendPasswordResetNotification($broker->createToken($fresh));
        }
        Mail::to($was)->queue(new EmailRestoreNoticeMail(EmailRestoreNoticeMail::RESTORED, AccountRules::maskEmail($window['to'])));
        $this->audit->record('email_change_reverted', $fresh->id, $fresh, 'Email put back to '.AccountRules::maskEmail($window['to']));
        $user->setRawAttributes($fresh->getAttributes(), true);

        return true;
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
