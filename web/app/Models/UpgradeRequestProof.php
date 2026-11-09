<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment receipt sent with an upgrade request (since v1.7, docs/API.md "Payment receipts"): a screenshot (always
 * re-encoded as WebP, with a 480 px thumbnail) or a PDF, encrypted on the recordings disk with its id bound in. Locked
 * once sent with "paid"; its files are erased 90 days after the request is decided, the row stays with `erased_at`.
 *
 * @property string $id
 * @property string $upgrade_request_id
 * @property int $user_id
 * @property string $kind image | pdf
 * @property string $mime
 * @property string $name
 * @property int $bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $sha256
 * @property string $path
 * @property string|null $thumb_path
 * @property Carbon|null $locked_at
 * @property Carbon|null $erased_at
 * @property Carbon $created_at
 * @property-read UpgradeRequest $request
 */
class UpgradeRequestProof extends Model
{
    use HasUlids;

    public const IMAGE = 'image';

    public const PDF = 'pdf';

    protected $fillable = ['upgrade_request_id', 'user_id', 'kind', 'mime', 'name', 'bytes', 'width', 'height', 'sha256', 'path', 'thumb_path', 'locked_at', 'erased_at'];

    /** @return BelongsTo<UpgradeRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(UpgradeRequest::class, 'upgrade_request_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isPdf(): bool
    {
        return $this->kind === self::PDF;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'locked_at' => 'datetime', 'erased_at' => 'datetime'];
    }
}
