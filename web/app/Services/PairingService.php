<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * One-time pairing codes (PLAN.md D2): 8 Crockford base32 chars, 10-minute TTL, single use, SHA-256 at rest.
 */
final class PairingService
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return string display form "XXXX-XXXX" (shown once) */
    public function createCode(User $user): string
    {
        $raw = self::randomCode();

        // The device joins the workspace the user is working in when they make the code (B20).
        app(PlanLimits::class)->assertDevices((int) $user->current_workspace_id, $user->id);
        DevicePairingCode::query()->create([
            'workspace_id' => $user->current_workspace_id,
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes((int) config('echoledger.pairing_code_minutes', 10)),
        ]);

        return substr($raw, 0, 4).'-'.substr($raw, 4);
    }

    private static function randomCode(): string
    {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::ALPHABET[random_int(0, 31)];
        }

        return $raw;
    }

    /**
     * "Sign in again" (since v1.6, docs/API.md "Signing a device in again"): a one-time code bound to one of the person's
     * own devices that is signed out, revoked or expired. Redeemed like any code, it brings that same row back. Another
     * person's device is never found (404).
     *
     * @return string display form "XXXX-XXXX" (shown once)
     */
    public function createBoundCode(User $user, string $deviceId): string
    {
        $device = Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereKey($deviceId)->firstOrFail();
        if ($device->status() === Device::ACTIVE) {
            throw ValidationException::withMessages(['device' => 'This device is signed in already.']);
        }
        $raw = self::randomCode();
        DevicePairingCode::query()->create([
            'workspace_id' => $device->workspace_id,
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes((int) config('echoledger.pairing_code_minutes', 10)),
            'bound_device_id' => $device->id,
        ]);

        return substr($raw, 0, 4).'-'.substr($raw, 4);
    }

    public static function normalize(string $code): string
    {
        $code = strtoupper((string) preg_replace('/[\s\-]/', '', $code));

        // Crockford: I/L → 1, O → 0
        return strtr($code, ['I' => '1', 'L' => '1', 'O' => '0']);
    }

    /** QR payload (docs/API.md): {"v":1,"url":...,"code":...,"pins":[...]} */
    public function qrPayload(string $displayCode): string
    {
        return (string) json_encode([
            'v' => 1,
            'url' => rtrim((string) config('app.url'), '/'),
            'code' => self::normalize($displayCode),
            'pins' => $this->settings->pins(),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array{name: string, platform: string, app_version?: string|null, os_version?: string|null}  $attributes
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function redeem(string $code, array $attributes, ?string $ip = null): array
    {
        $hash = hash('sha256', self::normalize($code));

        return DB::transaction(function () use ($hash, $attributes, $ip): array {
            /** @var DevicePairingCode|null $pairing */
            $pairing = DevicePairingCode::query()->where('code_hash', $hash)->lockForUpdate()->first();
            // Since v1.5 an account in its deletion grace signs nothing in, nor someone no longer in the code's workspace
            // (their codes are dropped then; this covers a race).
            $owner = $pairing === null ? null : User::query()->find($pairing->user_id);
            if ($pairing === null || $pairing->used_at !== null || $pairing->expires_at->isPast() || ! $owner instanceof User || $owner->isBeingDeleted() || $owner->isSuspended()
                || app(Workspaces::class)->roleOf($owner, $pairing->workspace_id) === null) {
                throw ValidationException::withMessages(['pairing_code' => 'This code is invalid, expired, or already used.']);
            }

            [$device, $token] = $pairing->bound_device_id !== null
                ? $this->signInAgain($pairing, $attributes, $ip)
                : $this->createDevice($pairing->workspace_id, $pairing->user_id, $attributes, $ip);
            $pairing->update(['used_at' => now(), 'device_id' => $device->id]);

            return [$device, $token];
        });
    }

    /**
     * Brings the code's bound row back (since v1.6): active again, its name and recordings kept, a new token (the old
     * ones stay revoked), the install id of the request when sent. Only on the row's own platform; the device limit
     * counts the row once.
     *
     * @param  array{name: string, platform: string, app_version?: string|null, os_version?: string|null, install_id?: string|null}  $attributes
     * @return array{0: Device, 1: NewAccessToken}
     */
    private function signInAgain(DevicePairingCode $pairing, array $attributes, ?string $ip): array
    {
        $device = Device::query()->withoutGlobalScopes()->where('user_id', $pairing->user_id)->whereKey((string) $pairing->bound_device_id)->lockForUpdate()->first();
        if (! $device instanceof Device) {
            throw ValidationException::withMessages(['pairing_code' => 'This code is invalid, expired, or already used.']);
        }
        if ($device->platform->value !== $attributes['platform']) {
            throw ValidationException::withMessages(['pairing_code' => "This code signs in {$device->readableName()} again; use it on that device."]);
        }
        if ($device->status() !== Device::ACTIVE) {
            app(PlanLimits::class)->assertDevices($device->workspace_id, $device->user_id);
        }
        $installId = $attributes['install_id'] ?? null;
        $device->tokens()->delete();
        $device->forceFill([
            'app_version' => $attributes['app_version'] ?? $device->app_version,
            'os_version' => $attributes['os_version'] ?? $device->os_version,
            'install_id' => is_string($installId) && $installId !== '' ? $installId : $device->install_id,
            'last_seen_at' => now(),
            'last_ip' => $ip,
            'signed_out_at' => null,
            'signed_out_by' => null,
        ])->save();
        $device->signedInAgain = true;
        app(AuditLog::class)->record('device_signed_in_again', $device->user_id, $device, "Signed in again: {$device->readableName()}");

        return [$device, $this->issueToken($device)];
    }

    /**
     * A new device of [$userId] in [$workspaceId] with its token, within the plan's device limit: a pairing code, or
     * since v1.5 its owner signing in (`$signedInWith` = password or browser). Exactly the same device either way.
     *
     * @param  array{name: string, platform: string, app_version?: string|null, os_version?: string|null, install_id?: string|null}  $attributes
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function createDevice(int $workspaceId, int $userId, array $attributes, ?string $ip, ?string $country = null, ?string $signedInWith = null): array
    {
        // Since v1.6 (P3): the same install of the same person signs in again on its own row ("Signed in again").
        $installId = $attributes['install_id'] ?? null;
        $again = is_string($installId) && $installId !== ''
            ? Device::query()->withoutGlobalScope(WorkspaceScope::class)->where('user_id', $userId)->where('install_id', $installId)->lockForUpdate()->first()
            : null;
        if ($again instanceof Device) {
            if ($again->workspace_id !== $workspaceId || $again->status() !== Device::ACTIVE) {
                app(PlanLimits::class)->assertDevices($workspaceId, $userId);
            }
            $again->tokens()->delete();
            $again->forceFill([
                'workspace_id' => $workspaceId,
                'name' => $again->name_chosen ? $again->name : $attributes['name'],
                'platform' => DevicePlatform::from($attributes['platform']),
                'app_version' => $attributes['app_version'] ?? null,
                'os_version' => $attributes['os_version'] ?? null,
                'last_seen_at' => now(),
                'last_ip' => $ip,
                'last_country' => $country,
                'signed_out_at' => null,
                'signed_out_by' => null,
            ])->save();
            $again->signedInWith = $signedInWith;
            $again->signedInAgain = true;
            app(AuditLog::class)->record('device_signed_in_again', $userId, $again, "Signed in again: {$again->readableName()}");

            return [$again, $this->issueToken($again)];
        }

        app(PlanLimits::class)->assertDevices($workspaceId, $userId);

        $device = new Device([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'name' => $attributes['name'],
            'platform' => DevicePlatform::from($attributes['platform']),
            'app_version' => $attributes['app_version'] ?? null,
            'os_version' => $attributes['os_version'] ?? null,
            'last_seen_at' => now(),
            'last_ip' => $ip,
            'last_country' => $country,
            'install_id' => is_string($installId) && $installId !== '' ? $installId : null,
        ]);
        $device->signedInWith = $signedInWith;
        $device->save();

        return [$device, $this->issueToken($device)];
    }

    /** @param  list<string>|null  $abilities  null = the full device set; a rotation passes the old token's abilities */
    public function issueToken(Device $device, ?array $abilities = null): NewAccessToken
    {
        return $device->createToken('device', $abilities ?? Device::ABILITIES, now()->addDays((int) config('echoledger.device_token_days', 365)));
    }
}
