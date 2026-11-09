<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff role (since v1.6, P2.5): what its people may do on the admin panel (App\Services\Staff\StaffAccess::PERMISSIONS).
 * The four that come ready cannot be deleted; their permissions can change.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property list<string> $permissions
 * @property bool $is_system
 */
class StaffRole extends Model
{
    protected $fillable = ['key', 'name', 'permissions', 'is_system'];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function grants(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }
}
