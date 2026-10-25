<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One access to a share link: `viewed`, `wrong_password` or `audio`. The IP address is kept only as a keyed hash.
 *
 * @property int $id
 * @property string $share_link_id
 * @property string $outcome
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property Carbon|null $viewed_at
 */
class ShareLinkView extends Model
{
    public $timestamps = false;

    protected $fillable = ['share_link_id', 'outcome', 'ip_hash', 'user_agent', 'viewed_at'];

    /** @return BelongsTo<ShareLink, $this> */
    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }
}
