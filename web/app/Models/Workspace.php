<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * The tenant boundary (B20): every device, pairing code, client, recording and usage row belongs to one workspace;
 * since v1.5 what each person makes in it is theirs alone (App\Models\Scopes\OwnerScope). Roles live on the membership
 * (`workspace_user.role`, App\Policies\WorkspacePolicy).
 *
 * @property int $id
 * @property string $name
 * @property int $plan_id
 * @property Carbon|null $plan_expires_at since v1.6: the plan lasts until then, then the default plan
 * @property-read Plan $plan
 */
class Workspace extends Model
{
    protected $fillable = ['name', 'plan_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['plan_expires_at' => 'datetime'];
    }

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
