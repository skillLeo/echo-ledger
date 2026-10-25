<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
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

        DevicePairingCode::query()->create([
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
            if ($pairing === null || $pairing->used_at !== null || $pairing->expires_at->isPast()) {
                throw ValidationException::withMessages(['pairing_code' => 'This pairing code is invalid, expired, or already used.']);
            }

            /** @var Device $device */
            $device = Device::query()->create([
                'user_id' => $pairing->user_id,
                'name' => $attributes['name'],
                'platform' => DevicePlatform::from($attributes['platform']),
                'app_version' => $attributes['app_version'] ?? null,
                'os_version' => $attributes['os_version'] ?? null,
                'last_seen_at' => now(),
                'last_ip' => $ip,
            ]);
            $pairing->update(['used_at' => now(), 'device_id' => $device->id]);

            return [$device, $this->issueToken($device)];
        });
    }

    /** @param  list<string>|null  $abilities  null = the full device set; a rotation passes the old token's abilities */
    public function issueToken(Device $device, ?array $abilities = null): NewAccessToken
    {
        return $device->createToken('device', $abilities ?? Device::ABILITIES, now()->addDays((int) config('echoledger.device_token_days', 365)));
    }
}
