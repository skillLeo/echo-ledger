<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A view-only link to one recording's transcript (and optionally its audio). Only the SHA-256 of the token is stored;
 * the link itself is shown once, when it is made.
 *
 * @property string $id
 * @property string $capture_session_id
 * @property string $token_hash
 * @property string|null $password_hash
 * @property bool $include_audio
 * @property string|null $view
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int $view_count
 * @property Carbon|null $last_viewed_at
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read CaptureSession $captureSession
 */
class ShareLink extends Model
{
    use HasUlids;

    protected $fillable = [
        'capture_session_id', 'token_hash', 'password_hash', 'include_audio', 'view', 'expires_at', 'revoked_at', 'view_count', 'last_viewed_at', 'created_by',
    ];

    protected $hidden = ['token_hash', 'password_hash'];

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class)->withTrashed();
    }

    /** @return HasMany<ShareLinkView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(ShareLinkView::class);
    }

    /** Usable now: not revoked, not expired, and the recording still exists. */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ! $this->captureSession->trashed();
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->capture_session_id,
            'has_password' => $this->password_hash !== null,
            'include_audio' => $this->include_audio,
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
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }
}
