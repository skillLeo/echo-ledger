<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The tenant boundary (B20): every device, pairing code, client, recording and usage row belongs to one workspace;
 * its members see all of it. Roles live on the membership (`workspace_user.role`).
 *
 * @property int $id
 * @property string $name
 * @property int $plan_id
 * @property-read Plan $plan
 */
class Workspace extends Model
{
    protected $fillable = ['name', 'plan_id'];

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function roleOf(User $user): ?WorkspaceRole
    {
        $role = $this->members()->whereKey($user->getKey())->first()?->getAttribute('pivot')?->getAttribute('role');

        return is_string($role) ? WorkspaceRole::tryFrom($role) : null;
    }
}
