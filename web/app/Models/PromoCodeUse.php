<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One use of a promo code (since v1.6.2): who, when and on which upgrade request. Only staff with the Plans permission
 * see uses; nobody sees anyone else's.
 *
 * @property int $id
 * @property int $promo_code_id
 * @property int $user_id
 * @property string|null $upgrade_request_id
 * @property Carbon $used_at
 * @property-read PromoCode $promoCode
 * @property-read User $user
 * @property-read UpgradeRequest|null $upgradeRequest
 */
class PromoCodeUse extends Model
{
    public $timestamps = false;

    protected $fillable = ['promo_code_id', 'user_id', 'upgrade_request_id', 'used_at'];

    /** @return BelongsTo<PromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UpgradeRequest, $this> */
    public function upgradeRequest(): BelongsTo
    {
        return $this->belongsTo(UpgradeRequest::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
