<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A view-only link to one recording's transcript (and optionally its audio), or since v1.5 to a project's recordings.
 * Only the SHA-256 of the token is stored; the link itself is shown once, when it is made. Since v1.5 a link says who
 * may open it (`audience`: anyone, anyone with the password, or the signed-in `people`) and whether its files may be
 * downloaded (`allow_download`); `include_audio` is whether the page plays the recording.
 *
 * @property string $id
 * @property string|null $capture_session_id null for a project link
 * @property string|null $client_id the project of a project link (v1.5)
 * @property string $token_hash
 * @property string|null $password_hash
 * @property string $audience public | password | people (v1.5)
 * @property list<string>|null $people the emails of a people-only link, lower case (v1.5)
 * @property bool $include_audio
 * @property bool $allow_download
 * @property string|null $view
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int $view_count
 * @property Carbon|null $last_viewed_at
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read CaptureSession|null $captureSession
 * @property-read Client|null $client
 */
class ShareLink extends Model
{
    use HasUlids;

    public const AUDIENCES = ['public', 'password', 'people'];

    protected $fillable = [
        'capture_session_id', 'client_id', 'token_hash', 'password_hash', 'audience', 'people', 'include_audio', 'allow_download', 'view',
        'expires_at', 'revoked_at', 'view_count', 'last_viewed_at', 'created_by',
    ];

    protected $hidden = ['token_hash', 'password_hash'];

    /**
     * The link names its recording itself: the workspace of whoever is signed in (a people-only link opened from
     * another workspace) must not hide it. Callers that list links filter by workspace explicitly.
     *
     * @return BelongsTo<CaptureSession, $this>
     */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class)->withTrashed()->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return BelongsTo<Client, $this> the project of a project link, found the same way */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return HasMany<ShareLinkView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(ShareLinkView::class);
    }

    /** `session` or `project` (v1.5). */
    public function kind(): string
    {
        return $this->client_id !== null ? 'project' : 'session';
    }

    /** The workspace of the recording or project the link opens. */
    public function workspaceId(): ?int
    {
        return $this->client_id !== null ? $this->client?->workspace_id : $this->captureSession?->workspace_id;
    }

    /** Usable now: not revoked, not expired, and the recording (or project) still exists. */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null || ($this->expires_at !== null && ! $this->expires_at->isFuture())) {
            return false;
        }

        return $this->client_id !== null ? $this->client !== null : $this->captureSession !== null && ! $this->captureSession->trashed();
    }

    /** Whether a person signed in with this email may open a people-only link. */
    public function allows(?string $email): bool
    {
        return $email !== null && in_array(mb_strtolower(trim($email)), $this->people ?? [], true);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            // Since v1.5: a recording's or a project's link.
            'kind' => $this->kind(),
            'session_id' => $this->capture_session_id,
            'client_id' => $this->client_id,
            'audience' => $this->audience,
            'people' => $this->audience === 'people' ? ($this->people ?? []) : null,
            'allow_download' => $this->allow_download,
            'has_password' => $this->audience === 'password' && $this->password_hash !== null,
            // v1.1 field, mirrors allow_download since v1.5 (older apps show it as "audio included").
            'include_audio' => $this->allow_download,
            // Since v1.2: the Show as view the page opens in (roman, english), or null for the original words.
            'view' => $this->view,
            'expires_at' => $this->expires_at?->toAtomString(),
            'revoked_at' => $this->revoked_at?->toAtomString(),
            'active' => $this->isActive(),
            'view_count' => $this->view_count,
            'last_viewed_at' => $this->last_viewed_at?->toAtomString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toAtomString(),
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'include_audio' => 'boolean',
            'allow_download' => 'boolean',
            'people' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }
}
