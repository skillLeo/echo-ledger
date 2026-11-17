<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invite link to a team (v1.8 P6.3, App\Services\Accounts\InviteLinks): anyone with it joins as a member or a
 * viewer while it is open (not revoked, not ended, uses left).
 *
 * @property int $id
 * @property int $workspace_id
 * @property WorkspaceRole $role member | viewer
 * @property string $token_hash
 * @property int|null $created_by
 * @property Carbon $expires_at
 * @property int|null $max_uses null: any number of people
 * @property int $uses
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
class WorkspaceInviteLink extends Model
{
    protected $fillable = ['workspace_id', 'role', 'token_hash', 'created_by', 'expires_at', 'max_uses', 'uses', 'revoked_at'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture() && ($this->max_uses === null || $this->uses < $this->max_uses);
    }

    /** @return array<string, mixed> as the API and the console show it (never the address: it is shown once) */
    public function toApi(): array
    {
        return [
            'id' => $this->id, 'role' => $this->role->value, 'expires_at' => $this->expires_at->toAtomString(),
            'max_uses' => $this->max_uses, 'uses' => $this->uses, 'open' => $this->usable(),
            'revoked_at' => $this->revoked_at?->toAtomString(), 'created_at' => $this->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => WorkspaceRole::class, 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'max_uses' => 'integer', 'uses' => 'integer'];
    }
}
