<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's request for a plan (since v1.6, P1.4, docs/API.md "Upgrade requests"): pending until the super admin
 * approves it (the plan, until a date or not) or declines it with a reply.
 *
 * @property string $id
 * @property int $user_id
 * @property int $workspace_id
 * @property string $plan
 * @property string|null $message
 * @property string $status
 * @property string|null $reply
 * @property string|null $approved_plan
 * @property Carbon|null $approved_until
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Workspace $workspace
 */
class UpgradeRequest extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected $fillable = ['user_id', 'workspace_id', 'plan', 'message', 'status', 'reply', 'approved_plan', 'approved_until', 'decided_by', 'decided_at'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['approved_until' => 'datetime', 'decided_at' => 'datetime'];
    }
}
