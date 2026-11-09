<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DevicePairingCode;
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
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::ALPHABET[random_int(0, 31)];
        }

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
            if ($pairing === null || $pairing->used_at !== null || $pairing->expires_at->isPast() || ! $owner instanceof User || $owner->isBeingDeleted()
                || app(Workspaces::class)->roleOf($owner, $pairing->workspace_id) === null) {
                throw ValidationException::withMessages(['pairing_code' => 'This code is invalid, expired, or already used.']);
            }

            [$device, $token] = $this->createDevice($pairing->workspace_id, $pairing->user_id, $attributes, $ip);
            $pairing->update(['used_at' => now(), 'device_id' => $device->id]);

            return [$device, $token];
        });
    }

    /**
     * A new device of [$userId] in [$workspaceId] with its token, within the plan's device limit: a pairing code, or
     * since v1.5 its owner signing in (`$signedInWith` = password or browser). Exactly the same device either way.
     *
     * @param  array{name: string, platform: string, app_version?: string|null, os_version?: string|null}  $attributes
     * @return array{0: Device, 1: NewAccessToken}
     */
    public function createDevice(int $workspaceId, int $userId, array $attributes, ?string $ip, ?string $country = null, ?string $signedInWith = null): array
    {
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
