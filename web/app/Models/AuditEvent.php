<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of the audit log (plan B16). Kept for a year, then pruned by `model:prune`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $event
 * @property string $actor `web`, `device` or `system`
 * @property string|null $device_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $summary
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class AuditEvent extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 365;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'event', 'actor', 'device_id', 'subject_type', 'subject_id', 'summary', 'ip', 'user_agent', 'meta'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
