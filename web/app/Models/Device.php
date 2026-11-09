<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Enums\SourceDevice;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\AuditLog;
use App\Support\DeviceNames;
use Database\Factories\DeviceFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $last_country two letters, when the server's proxy names the country (since v1.5)
 * @property string|null $install_id the app's own id of its install (since v1.6): signing in again reuses this row
 * @property bool $name_chosen the person renamed it (since v1.6): the name is shown as it is
 * @property Carbon|null $signed_out_at since v1.6 a signed-out device stays listed (its tokens are gone)
 * @property string|null $signed_out_by self (the person) | admin (an owner or admin of the workspace)
 * @property Carbon|null $created_at
 * @property-read User $user
 */
class Device extends Model implements Authenticatable
{
    /** @use HasFactory<DeviceFactory> */
    use AuthenticatableTrait, BelongsToWorkspace, HasApiTokens, HasFactory, HasUlids;

    /** Every device token carries exactly these abilities. */
    public const ABILITIES = ['config:read', 'stt:token', 'sessions:write', 'recordings:upload', 'library:read'];

    protected $fillable = ['workspace_id', 'user_id', 'name', 'platform', 'app_version', 'os_version', 'last_seen_at', 'last_ip', 'last_country', 'install_id'];

    /** Days without a call after which a device shows as Expired and no longer counts against the plan (since v1.6). */
    public const EXPIRES_AFTER_DAYS = 90;

    public const ACTIVE = 'active';

    public const SIGNED_OUT = 'signed_out';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    /** What the console calls each status. */
    public const STATUS_LABELS = [self::ACTIVE => 'Active', self::SIGNED_OUT => 'Signed out by you', self::REVOKED => 'Revoked by admin', self::EXPIRED => 'Expired'];

    /** How a new device came (since v1.5): null = a pairing code, `password` or `browser` = its owner signed in. Not stored. */
    public ?string $signedInWith = null;

    /** Since v1.6: the sign-in reused this install's row ("Signed in again"). Not stored. */
    public bool $signedInAgain = false;

    /** Active, signed out by its person, revoked by an owner or admin, or expired (90 days without a call). */
    public function status(): string
    {
        return match (true) {
            $this->signed_out_at !== null => $this->signed_out_by === 'admin' ? self::REVOKED : self::SIGNED_OUT,
            ($this->last_seen_at ?? $this->created_at)?->lt(now()->subDays(self::EXPIRES_AFTER_DAYS)) === true => self::EXPIRED,
            default => self::ACTIVE,
        };
    }

    /** "Galaxy S25 Ultra", "Windows 11 PC": the name people read (App\Support\DeviceNames). */
    public function readableName(): string
    {
        return DeviceNames::readable($this->name, $this->platform->value, $this->os_version, (bool) $this->name_chosen);
    }

    /**
     * Signs the device out (since v1.6): its tokens stop working at once, the row stays as "Signed out by you" or
     * "Revoked by admin" until it is removed from the list. Its recordings stay.
     */
    public function signOut(string $by, ?int $actorId = null): void
    {
        $this->tokens()->delete();
        $this->forceFill(['signed_out_at' => now(), 'signed_out_by' => $by === 'admin' ? 'admin' : 'self'])->save();
        app(AuditLog::class)->record('device_signed_out', $actorId ?? $this->user_id, $this, ($by === 'admin' ? 'Device revoked by an admin: ' : 'Device signed out: ').$this->readableName());
    }

    /**
     * The device as the device lists show it (GET /api/security, since v1.6 with its readable name, its status and
     * when it was signed out).
     *
     * @return array<string, mixed>
     */
    public function listItem(?string $currentId = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->readableName(),
            'platform' => $this->platform->value,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at?->toAtomString(),
            'paired_at' => $this->created_at?->toAtomString(),
            'current' => $this->id === $currentId,
            'status' => $this->status(),
            'signed_out_at' => $this->signed_out_at?->toAtomString(),
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->whereNull('signed_out_at')->where(fn (Builder $q) => $q->where('last_seen_at', '>=', now()->subDays(self::EXPIRES_AFTER_DAYS))
            ->orWhere(fn (Builder $r) => $r->whereNull('last_seen_at')->where('created_at', '>=', now()->subDays(self::EXPIRES_AFTER_DAYS))));
    }

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
            'name_chosen' => 'boolean',
            'signed_out_at' => 'datetime',
        ];
    }
}
