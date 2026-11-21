<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Staff\StaffAccess;
use App\Support\Api\AccountRules;
use App\Support\Brand;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * Two-step sign-in set up from the apps (since 2.0.0-l, docs/API.md "Account and security from the apps" › 7), with
 * the provider and storage the console's Settings use (Filament's AppAuthentication: the same secret, recovery codes,
 * TOTP window and replay protection). Nothing is saved until the first code is confirmed: the new secret waits
 * encrypted in the cache for 10 minutes under a ceremony id of this person. Secrets, otpauth URIs and recovery codes
 * are answered once and never logged; turning it on and off is audited by AuditLog's listener (mfa_enabled,
 * mfa_disabled).
 */
final class TwoFactorSetup
{
    public const CEREMONY_MINUTES = 10;

    /** Wrong codes one ceremony takes before it ends. */
    public const CEREMONY_TRIES = 5;

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * POST /api/account/two-factor: a new secret to put in an authenticator app.
     *
     * @return array{ceremony: string, secret: string, otpauth_uri: string, issuer: string, label: string, digits: int, period: int, algorithm: string}
     */
    public function begin(User $user): array
    {
        $this->assertNotEnabled($user);
        $secret = $this->provider()->generateSecret();
        $ceremony = Str::random(40);
        Cache::put(self::key($user, $ceremony), Crypt::encryptString((string) json_encode(['secret' => $secret, 'tries' => 0])), now()->addMinutes(self::CEREMONY_MINUTES));
        $google = app(Google2FA::class);

        return [
            'ceremony' => $ceremony,
            'secret' => $secret,
            'otpauth_uri' => $google->getQRCodeUrl(Brand::name(), $user->email, $secret),
            'issuer' => Brand::name(),
            'label' => AccountRules::maskEmail($user->email),
            'digits' => (int) $google->getOneTimePasswordLength(),
            'period' => (int) $google->getKeyRegeneration(),
            'algorithm' => strtoupper((string) $google->getAlgorithm()),
        ];
    }

    /** `409 already_enabled`, before any proof is asked (and used up). */
    public function assertNotEnabled(User $user): void
    {
        if ($user->hasTwoFactor()) {
            throw new ApiError(409, 'already_enabled', 'Two-step sign-in is on already.');
        }
    }

    /**
     * POST /api/account/two-factor/confirm: the first code turns it on; answers the recovery codes, this once.
     *
     * @return list<string>
     */
    public function confirm(User $user, string $ceremony, #[\SensitiveParameter] string $code): array
    {
        if ($user->hasTwoFactor()) {
            throw new ApiError(409, 'already_enabled', 'Two-step sign-in is on already.');
        }
        $invalid = new ApiError(422, 'invalid_otp', 'That code is wrong. Check the time on your phone and try the newest code.');
        $key = self::key($user, $ceremony);
        $held = $this->held($key);
        if ($held === null) {
            throw new ApiError(422, 'invalid_otp', 'This set-up has ended. Start it again.');
        }
        $provider = $this->provider();
        if (preg_match('/^\d{6}$/', trim($code)) !== 1 || ! $provider->verifyCode(trim($code), $held['secret'], shouldPreventCodeReuse: true)) {
            $tries = $held['tries'] + 1;
            $tries >= self::CEREMONY_TRIES
                ? Cache::forget($key)
                : Cache::put($key, Crypt::encryptString((string) json_encode([...$held, 'tries' => $tries])), now()->addMinutes(self::CEREMONY_MINUTES));

            throw $invalid;
        }
        Cache::forget($key);
        $codes = array_values($provider->generateRecoveryCodes());
        DB::transaction(function () use ($provider, $user, $held, $codes): void {
            $provider->saveSecret($user, $held['secret']);
            $provider->saveRecoveryCodes($user, $codes);
        });

        return $codes;
    }

    /**
     * POST /api/account/two-factor/recovery-codes (the proof checked already): new codes, the old ones stop working.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        if (! $user->hasTwoFactor()) {
            throw new ApiError(409, 'not_enabled', 'Two-step sign-in is off: there are no recovery codes to make again.');
        }
        $provider = $this->provider();
        $codes = array_values($provider->generateRecoveryCodes());
        $provider->saveRecoveryCodes($user, $codes);
        $this->audit->record('recovery_codes_regenerated', $user->id, $user);

        return $codes;
    }

    /** DELETE /api/account/two-factor (an authenticator or recovery code checked already): `409 required_for_staff`. */
    public function assertMayDisable(User $user): void
    {
        if (StaffAccess::isStaff($user)) {
            throw new ApiError(409, 'required_for_staff', 'Every member of staff needs two-step sign-in, so it stays on.');
        }
    }

    public function disable(User $user): void
    {
        $provider = $this->provider();
        DB::transaction(function () use ($provider, $user): void {
            $provider->saveSecret($user, null);
            $provider->saveRecoveryCodes($user, null);
        });
    }

    /** How many recovery codes the person has left (0 without two-step). */
    public static function recoveryCodesLeft(User $user): int
    {
        return $user->hasTwoFactor() ? count((array) $user->getAppAuthenticationRecoveryCodes()) : 0;
    }

    /** @return array{secret: string, tries: int}|null */
    private function held(string $key): ?array
    {
        $value = Cache::get($key);
        if (! is_string($value)) {
            return null;
        }
        try {
            $held = json_decode(Crypt::decryptString($value), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($held) && is_string($held['secret'] ?? null) ? ['secret' => $held['secret'], 'tries' => (int) ($held['tries'] ?? 0)] : null;
    }

    private function provider(): AppAuthentication
    {
        foreach (Filament::getPanel('app')->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider instanceof AppAuthentication) {
                return $provider;
            }
        }

        throw new RuntimeException('Two-step sign-in is not set up on the console.');
    }

    private static function key(User $user, string $ceremony): string
    {
        return 'account-two-factor:'.$user->id.':'.hash('sha256', $ceremony);
    }
}
