<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Http\Middleware\EnsureNotSuspended;
use App\Mail\DeletionCancelledMail;
use App\Models\Device;
use App\Models\DeviceSignInCode;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use App\Support\DeviceNames;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * A phone or computer signing in to its owner's account (since v1.5, docs/API.md "Signing in with an account"): with
 * the email and password (and the two-step code) or through the browser with PKCE. Either way the device and its token
 * are exactly those of a paired device, in the person's current workspace and within the plan's device limit; the
 * account gets a "New sign-in" email and the audit log an entry.
 */
final class DeviceSignIn
{
    /** Minutes a browser sign-in code works. */
    public const CODE_MINUTES = 5;

    /** The only redirect the browser sign-in sends codes to. */
    public const REDIRECT_URI = 'echoledger://signed-in';

    /**
     * The cache key of the hash checked when no account has the email, so a wrong email takes as long as a wrong
     * password. It is made once per server, not per request: making it costs as much as checking it.
     */
    private const DUMMY_HASH_KEY = 'auth:dummy-password-hash';

    public function __construct(
        private readonly PairingService $pairing,
        private readonly SignInGuard $guard,
        private readonly TwoFactorCodes $codes,
        private readonly Workspaces $workspaces,
        private readonly LoginAlerts $alerts,
        private readonly AuditLog $audit,
        private readonly EmailCodes $emailCodes,
        private readonly SignUp $signUp,
        private readonly AccountDeletion $deletion,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * POST /api/auth/password. Since 2.0.0-l an account in its deletion grace is `403 deletion_scheduled` once the
     * password and two-step code are right (the code is not used up), and the same call with `"keep": true` keeps the
     * account and signs in.
     *
     * @param  array{email: string, password: string, otp?: string|null, keep?: bool|null, name: string, platform: string, app_version?: string|null, os_version?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function withPassword(array $data, ?string $ip, ?string $country): array
    {
        $keep = (bool) ($data['keep'] ?? false);
        $email = Str::lower(trim($data['email']));
        $this->guard->assertNotLocked($email);

        $user = User::query()->where('email', $email)->first();
        $hash = $user instanceof User ? $user->password : self::dummyHash();
        $passwordOk = Hash::check($data['password'], $hash);
        if (! $user instanceof User || ! $passwordOk) {
            $this->failed($email, $user);

            throw new ApiError(422, 'invalid_credentials', 'Wrong email or password.');
        }
        if ($user->hasTwoFactor()) {
            $otp = trim((string) ($data['otp'] ?? ''));
            if ($otp === '') {
                throw new ApiError(401, 'otp_required', 'Enter the code from your authenticator app.');
            }
            $this->assertNotScheduled($user, $keep, fn (): bool => $this->codes->peek($user, $otp), $email);
            if (! $this->codes->verify($user, $otp)) {
                $this->failed($email, $user);

                throw new ApiError(422, 'invalid_otp', 'That code is wrong or was used already.');
            }
        }
        $this->assertNotScheduled($user, $keep);
        $workspaceId = $this->assertCanSignIn($user, $keep);
        $this->guard->clear($email);

        return $this->create($user, $workspaceId, $data, $ip, $country, 'password', $keep);
    }

    /**
     * POST /api/auth/code/verify (since v1.6): the emailed code. An account signs in (with its two-step code when it has
     * one: without it `401 otp_required`, and the same email code stays good); a new address gets the one-time sign-up
     * token for POST /api/auth/sign-up. The code proves the address, so an account not confirmed yet is confirmed.
     *
     * Since 2.0.0-l an account in its deletion grace answers `403 deletion_scheduled` (the email code stays good) and
     * keeps the account with `"keep": true`, as POST /api/auth/password does.
     *
     * @param  array{email: string, code: string, otp?: string|null, keep?: bool|null, name: string, platform: string, app_version?: string|null, os_version?: string|null, install_id?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}|array{sign_up_token: string}
     */
    public function withEmailCode(array $data, ?string $ip, ?string $country): array
    {
        $keep = (bool) ($data['keep'] ?? false);
        $email = EmailCodes::normalize($data['email']);
        $row = $this->emailCodes->check($email, $data['code']);
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            $this->emailCodes->assertMaySignUp($email);

            return ['sign_up_token' => $this->emailCodes->signUpToken($row)];
        }
        if ($user->hasTwoFactor()) {
            $this->guard->assertNotLocked($email);
            $otp = trim((string) ($data['otp'] ?? ''));
            if ($otp === '') {
                throw new ApiError(401, 'otp_required', 'Enter the code from your authenticator app.');
            }
            $this->assertNotScheduled($user, $keep, fn (): bool => $this->codes->peek($user, $otp), $email);
            if (! $this->codes->verify($user, $otp)) {
                $this->failed($email, $user);

                throw new ApiError(422, 'invalid_otp', 'That code is wrong or was used already.');
            }
        }
        $this->assertNotScheduled($user, $keep);
        $this->emailCodes->useUp($row);
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
        $workspaceId = $this->assertCanSignIn($user, $keep);
        $this->guard->clear($email);

        return $this->create($user, $workspaceId, $data, $ip, $country, 'code', $keep);
    }

    /**
     * POST /api/auth/sign-up (since v1.6): the account of the address the code proved (confirmed, Terms accepted), its
     * personal workspace on the default plan, and the device signed in. `name` is the person's; the device is named
     * `device_name`, or after the platform when the app sends none.
     *
     * @param  array{sign_up_token: string, name: string, device_name?: string|null, platform: string, app_version?: string|null, os_version?: string|null, install_id?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function signUp(array $data, ?string $ip, ?string $country): array
    {
        $this->emailCodes->assertSignUpQuota($ip);
        $user = DB::transaction(function () use ($data): User {
            $email = $this->emailCodes->redeemSignUpToken($data['sign_up_token']);
            if (User::query()->where('email', $email)->exists()) {
                throw new ApiError(422, 'invalid_code', 'An account with this email exists already. Sign in instead.');
            }
            $this->emailCodes->assertMaySignUp($email);

            return $this->signUp->create(['name' => $data['name'], 'email' => $email, 'password' => Str::password(40)], emailVerified: true, via: 'code', acceptedTerms: true);
        });
        $this->emailCodes->countSignUp($ip);
        $deviceName = trim((string) ($data['device_name'] ?? ''));
        $device = [
            ...$data,
            'name' => $deviceName !== '' ? $deviceName : DeviceNames::fallback($data['platform'], $data['os_version'] ?? null),
        ];

        return $this->create($user, $this->assertCanSignIn($user), $device, $ip, $country, 'code');
    }

    /** A hash at the server's current cost that no password matches; made again only when the hashing settings change. */
    private static function dummyHash(): string
    {
        $hash = Cache::get(self::DUMMY_HASH_KEY);
        if (! is_string($hash) || Hash::needsRehash($hash)) {
            $hash = Hash::make(Str::random(40));
            Cache::forever(self::DUMMY_HASH_KEY, $hash);
        }

        return $hash;
    }

    /**
     * The checks shared by both ways in: a confirmed email, not in the deletion grace, a workspace to act in.
     * Returns that workspace (the person's current one). With [$keep] (since 2.0.0-l, the apps' "Keep my account") the
     * deletion grace is let through: create() then cancels the deletion with the sign-in.
     */
    public function assertCanSignIn(User $user, bool $keep = false): int
    {
        // Since v1.6: the super admin's suspend switch.
        if ($user->isSuspended()) {
            throw new ApiError(403, 'account_suspended', EnsureNotSuspended::MESSAGE);
        }
        if (! $user->hasVerifiedEmail()) {
            throw new ApiError(403, 'email_not_verified', 'Confirm your email first: open the link in the email the console sent you.');
        }
        if ($user->isBeingDeleted() && ! $keep) {
            throw new ApiError(403, 'not_allowed', 'This account is being deleted. Sign in on the console and choose "Keep my account" to use it again.');
        }
        $workspace = $this->workspaces->current($user);
        if ($workspace === null) {
            throw new ApiError(403, 'not_allowed', 'This account belongs to no workspace. Ask a workspace owner to add you.');
        }

        return $workspace->id;
    }

    /**
     * The apps' sign-in during the deletion grace (since 2.0.0-l): `403 deletion_scheduled {"deletes_at"}` unless the
     * call says `"keep": true`. With [$proof] (the two-step code, checked without using it up) the answer comes only for
     * a right code, so it says nothing to someone who knows only the password; a wrong one counts as a failure.
     * Nothing is used up, so the same call with `keep` signs in.
     *
     * @param  (callable(): bool)|null  $proof
     */
    private function assertNotScheduled(User $user, bool $keep, ?callable $proof = null, ?string $email = null): void
    {
        if (! $user->isBeingDeleted() || $keep) {
            return;
        }
        if ($proof !== null && ! $proof()) {
            $this->failed((string) $email, $user);

            throw new ApiError(422, 'invalid_otp', 'That code is wrong or was used already.');
        }
        $date = $user->deletes_at?->copy()->setTimezone($this->settings->displayTimezone())->format('M j, Y');

        throw new ApiError(403, 'deletion_scheduled', "This account is being deleted on {$date}. Sign in on the console and choose Keep my account.",
            ['deletes_at' => $user->deletes_at?->toAtomString()]);
    }

    /**
     * The person approved "Sign in EchoLedger on <name>?" in the browser: a one-time code for the app's redirect.
     *
     * @param  array{code_challenge: string, name: string, platform: string}  $request
     */
    public function approve(User $user, array $request): string
    {
        $workspaceId = $this->assertCanSignIn($user);
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DeviceSignInCode::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'code_hash' => hash('sha256', $code),
            'code_challenge' => $request['code_challenge'],
            'name' => mb_substr($request['name'], 0, 120),
            'platform' => $request['platform'],
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);

        return $code;
    }

    /**
     * POST /api/auth/device/token: the code and the verifier whose SHA-256 the browser was given. A wrong verifier uses
     * the code up, so a code that leaked cannot be tried against many verifiers. Since 2.0.0-l an account in its
     * deletion grace is `403 deletion_scheduled` and the code stays good for its 5 minutes; the same call with
     * `"keep": true` keeps the account and signs in.
     *
     * @param  array{code: string, code_verifier: string, keep?: bool|null, name: string, platform: string, app_version?: string|null, os_version?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function withCode(array $data, ?string $ip, ?string $country): array
    {
        $keep = (bool) ($data['keep'] ?? false);
        $invalid = new ApiError(422, 'invalid_code', 'This sign-in code is wrong, used or expired. Start the sign-in again.');
        [$found, $user] = DB::transaction(function () use ($data, $keep): array {
            $row = DeviceSignInCode::query()->where('code_hash', hash('sha256', $data['code']))->lockForUpdate()->first();
            if (! $row instanceof DeviceSignInCode || $row->used_at !== null || $row->expires_at->isPast()) {
                return [null, null];
            }
            if (! hash_equals($row->code_challenge, self::challengeOf($data['code_verifier']))) {
                $row->forceFill(['used_at' => now()])->save();

                return [null, null];
            }
            $user = User::query()->find($row->user_id);
            // The right verifier of an account in its deletion grace: the code stays good for the call with `keep`.
            if (! ($user instanceof User && $user->isBeingDeleted() && ! $keep)) {
                $row->forceFill(['used_at' => now()])->save();
            }

            return [$row, $user];
        });
        if (! $found instanceof DeviceSignInCode || ! $user instanceof User) {
            throw $invalid;
        }
        $this->assertNotScheduled($user, $keep);
        $this->assertCanSignIn($user, $keep);
        // The workspace it was approved for, while the person still belongs to it.
        if ($this->workspaces->roleOf($user, $found->workspace_id) === null) {
            throw new ApiError(403, 'not_allowed', 'You no longer belong to the workspace this sign-in was for. Sign in again.');
        }

        return $this->create($user, $found->workspace_id, $data, $ip, $country, 'browser', $keep);
    }

    /** BASE64URL(SHA-256(verifier)) without padding (RFC 7636, S256). */
    public static function challengeOf(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param  array{name: string, platform: string, app_version?: string|null, os_version?: string|null, install_id?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}
     */
    private function create(User $user, int $workspaceId, array $data, ?string $ip, ?string $country, string $via, bool $keep = false): array
    {
        $attributes = ['name' => $data['name'], 'platform' => $data['platform'], 'app_version' => $data['app_version'] ?? null, 'os_version' => $data['os_version'] ?? null, 'install_id' => $data['install_id'] ?? null];
        // "Keep my account" (since 2.0.0-l) counts only with the sign-in: a device that cannot be added keeps nothing.
        $kept = $keep && $user->isBeingDeleted();
        [$device, $token] = DB::transaction(function () use ($kept, $user, $workspaceId, $attributes, $ip, $country, $via): array {
            if ($kept) {
                $this->deletion->cancel($user);
            }

            return $this->pairing->createDevice($workspaceId, $user->id, $attributes, $ip, $country, $via);
        });
        if ($kept) {
            Mail::to($user->email)->queue(new DeletionCancelledMail);
        }
        $this->alerts->deviceSignedIn($device);

        return [$device, $token];
    }

    private function failed(string $email, ?User $user): void
    {
        $this->guard->fail($email, $user?->id);
        $this->audit->record('login_failed', $user?->id, null, 'App sign-in failed for '.Str::limit($email, 120, ''));
    }
}
