<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time device pairing code. Only the SHA-256 of the normalized code is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $device_id
 */
class DevicePairingCode extends Model
{
    use BelongsToWorkspace, Prunable;

    protected $fillable = ['workspace_id', 'user_id', 'code_hash', 'expires_at', 'used_at', 'device_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
