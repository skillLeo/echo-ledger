<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Limits of a workspace (B20): minutes per month, storage and paired devices. `null` means no limit. Enforced in
 * one place, App\Services\Workspaces\PlanLimits. No billing.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int|null $monthly_minutes
 * @property int|null $storage_bytes
 * @property int|null $max_devices
 */
class Plan extends Model
{
    public const PERSONAL = 'personal';

    /** Generous defaults of the plan every existing and new workspace gets: nobody notices them today. */
    public const PERSONAL_LIMITS = ['monthly_minutes' => 30000, 'storage_bytes' => 500 * 1024 ** 3, 'max_devices' => 25];

    protected $fillable = ['key', 'name', 'monthly_minutes', 'storage_bytes', 'max_devices'];

    public static function personal(): self
    {
        return self::query()->firstOrCreate(['key' => self::PERSONAL], ['name' => 'Personal', ...self::PERSONAL_LIMITS]);
    }

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monthly_minutes' => 'integer', 'storage_bytes' => 'integer', 'max_devices' => 'integer'];
    }
}
