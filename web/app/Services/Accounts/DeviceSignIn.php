<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Models\Device;
use App\Models\DeviceSignInCode;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\PairingService;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
    ) {}

    /**
     * POST /api/auth/password.
     *
     * @param  array{email: string, password: string, otp?: string|null, name: string, platform: string, app_version?: string|null, os_version?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function withPassword(array $data, ?string $ip, ?string $country): array
    {
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
            if (! $this->codes->verify($user, $otp)) {
                $this->failed($email, $user);

                throw new ApiError(422, 'invalid_otp', 'That code is wrong or was used already.');
            }
        }
        $workspaceId = $this->assertCanSignIn($user);
        $this->guard->clear($email);

        return $this->create($user, $workspaceId, $data, $ip, $country, 'password');
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
     * Returns that workspace (the person's current one).
     */
    public function assertCanSignIn(User $user): int
    {
        if (! $user->hasVerifiedEmail()) {
            throw new ApiError(403, 'email_not_verified', 'Confirm your email first: open the link in the email the console sent you.');
        }
        if ($user->isBeingDeleted()) {
            throw new ApiError(403, 'not_allowed', 'This account is being deleted. Sign in on the console and choose "Keep my account" to use it again.');
        }
        $workspace = $this->workspaces->current($user);
        if ($workspace === null) {
            throw new ApiError(403, 'not_allowed', 'This account belongs to no workspace. Ask a workspace owner to add you.');
        }

        return $workspace->id;
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
     * the code up, so a code that leaked cannot be tried against many verifiers.
     *
     * @param  array{code: string, code_verifier: string, name: string, platform: string, app_version?: string|null, os_version?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function withCode(array $data, ?string $ip, ?string $country): array
    {
        $invalid = new ApiError(422, 'invalid_code', 'This sign-in code is wrong, used or expired. Start the sign-in again.');
        $found = DB::transaction(function () use ($data): ?DeviceSignInCode {
            $row = DeviceSignInCode::query()->where('code_hash', hash('sha256', $data['code']))->lockForUpdate()->first();
            if (! $row instanceof DeviceSignInCode || $row->used_at !== null || $row->expires_at->isPast()) {
                return null;
            }
            $row->forceFill(['used_at' => now()])->save();

            return hash_equals($row->code_challenge, self::challengeOf($data['code_verifier'])) ? $row : null;
        });
        if (! $found instanceof DeviceSignInCode) {
            throw $invalid;
        }
        $user = User::query()->find($found->user_id);
        if (! $user instanceof User) {
            throw $invalid;
        }
        $this->assertCanSignIn($user);
        // The workspace it was approved for, while the person still belongs to it.
        if ($this->workspaces->roleOf($user, $found->workspace_id) === null) {
            throw new ApiError(403, 'not_allowed', 'You no longer belong to the workspace this sign-in was for. Sign in again.');
        }

        return $this->create($user, $found->workspace_id, $data, $ip, $country, 'browser');
    }

    /** BASE64URL(SHA-256(verifier)) without padding (RFC 7636, S256). */
    public static function challengeOf(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param  array{name: string, platform: string, app_version?: string|null, os_version?: string|null}  $data
     * @return array{0: Device, 1: NewAccessToken}
     */
    private function create(User $user, int $workspaceId, array $data, ?string $ip, ?string $country, string $via): array
    {
        $attributes = ['name' => $data['name'], 'platform' => $data['platform'], 'app_version' => $data['app_version'] ?? null, 'os_version' => $data['os_version'] ?? null];
        [$device, $token] = DB::transaction(fn (): array => $this->pairing->createDevice($workspaceId, $user->id, $attributes, $ip, $country, $via));
        $this->alerts->deviceSignedIn($device);

        return [$device, $token];
    }

    private function failed(string $email, ?User $user): void
    {
        $this->guard->fail($email, $user?->id);
        $this->audit->record('login_failed', $user?->id, null, 'App sign-in failed for '.Str::limit($email, 120, ''));
    }
}
