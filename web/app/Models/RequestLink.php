<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\OwnedByMaker;
use App\Models\Scopes\OwnerScope;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request link (docs/API.md "Request links", since v1.5): anyone with it can send files on a public page; sound
 * and video become recordings in its project (or the Inbox), documents and images go to the project's Files. Only the
 * SHA-256 of the token is stored; the link is shown once.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $client_id
 * @property string $token_hash
 * @property string $title
 * @property Carbon|null $expires_at
 * @property int $max_files
 * @property int $max_mb
 * @property int $received
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read Client|null $client
 * @property-read User $user
 */
class RequestLink extends Model
{
    use BelongsToWorkspace, HasUlids, OwnedByMaker;

    protected $fillable = ['workspace_id', 'user_id', 'client_id', 'token_hash', 'title', 'expires_at', 'max_files', 'max_mb', 'received', 'revoked_at'];

    protected $hidden = ['token_hash'];

    /** @return BelongsTo<Client, $this> the link names its project itself, whoever is signed in */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes([WorkspaceScope::class, OwnerScope::class]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RequestUpload, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(RequestUpload::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'client_id' => $this->client_id,
            'expires_at' => $this->expires_at?->toAtomString(),
            'max_files' => $this->max_files,
            'max_mb' => $this->max_mb,
            'received' => $this->received,
            'revoked_at' => $this->revoked_at?->toAtomString(),
            'active' => $this->isActive(),
            'created_at' => $this->created_at->toAtomString(),
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'max_files' => 'integer', 'max_mb' => 'integer', 'received' => 'integer'];
    }
}
