<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to make an account and join a workspace (since v1.5). Only the SHA-256 of its token is stored; the
 * link in the email is the only copy of the token.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property-read Workspace $workspace
 */
class WorkspaceInvitation extends Model
{
    protected $fillable = ['workspace_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function usable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => WorkspaceRole::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
