<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * "Export my data" (since v1.5, docs/API.md "Your account"): one ZIP of everything the account owns, made by a queued
 * job, stored encrypted on the recordings disk under `path`, and deleted 24 hours after it is ready.
 *
 * @property string $id
 * @property int $user_id
 * @property string $status making | ready | failed
 * @property string|null $path
 * @property int|null $bytes the ZIP's size
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 */
class AccountExport extends Model
{
    use HasUlids;

    public const HOURS = 24;

    protected $fillable = ['user_id', 'status', 'path', 'bytes', 'error', 'expires_at', 'completed_at'];

    public function downloadable(): bool
    {
        return $this->status === 'ready' && $this->path !== null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** The signed link (no sign-in needed) that works until the export expires. */
    public function downloadUrl(): ?string
    {
        return $this->downloadable() && $this->expires_at !== null
            ? URL::temporarySignedRoute('account.export.download', $this->expires_at, ['export' => $this->id])
            : null;
    }

    /** @return array{id: string, status: string, url: string|null, expires_at: string|null} */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'url' => $this->downloadUrl(),
            'expires_at' => $this->status === 'ready' ? $this->expires_at?->toAtomString() : null,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['bytes' => 'integer', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
