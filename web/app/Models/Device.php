<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Enums\SourceDevice;
use Database\Factories\DeviceFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A paired phone or desktop app. Owns its Sanctum tokens (docs/API.md "Pairing").
 *
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property DevicePlatform $platform
 * @property string|null $app_version
 * @property string|null $os_version
 * @property Carbon|null $last_seen_at
 * @property string|null $last_ip
 * @property-read User $user
 */
class Device extends Model implements Authenticatable
{
    /** @use HasFactory<DeviceFactory> */
    use AuthenticatableTrait, HasApiTokens, HasFactory, HasUlids;

    /** Every device token carries exactly these abilities. */
    public const ABILITIES = ['config:read', 'stt:token', 'sessions:write', 'recordings:upload', 'library:read'];

    protected $fillable = ['user_id', 'name', 'platform', 'app_version', 'os_version', 'last_seen_at', 'last_ip'];

    public function sourceDevice(): SourceDevice
    {
        return $this->platform->sourceDevice();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CaptureSession, $this> */
    public function captureSessions(): HasMany
    {
        return $this->hasMany(CaptureSession::class);
    }

    public function getRememberTokenName(): string
    {
        return ''; // devices never use "remember me"
    }

    protected static function booted(): void
    {
        // morphMany does not cascade: revoke tokens whenever a device is removed.
        static::deleting(fn (Device $device) => $device->tokens()->delete());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'datetime',
        ];
    }
}
