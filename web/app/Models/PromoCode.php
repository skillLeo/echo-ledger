<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A promo code (since v1.6.2, docs/API.md "Promo codes"): one plan, a discount of 1–100 %, an optional end date and
 * number of uses, one use per person unless staff say otherwise, and the months the plan lasts once switched on. Made
 * by staff with the Plans permission; customers never see the list (App\Services\Entitlements\PromoCodes checks one).
 *
 * @property int $id
 * @property string $code kept in capitals
 * @property string $plan the plan's key
 * @property int $percent
 * @property Carbon|null $ends_at
 * @property int|null $max_uses
 * @property bool $one_per_person
 * @property int $months
 * @property bool $active
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read int|null $uses_count
 */
class PromoCode extends Model
{
    /** 6–24 characters A-Z 0-9 -, matched without case. */
    public const PATTERN = '/^[A-Z0-9-]{6,24}$/';

    protected $fillable = ['code', 'plan', 'percent', 'ends_at', 'max_uses', 'one_per_person', 'months', 'active', 'note', 'created_by'];

    /** The code as it is kept and matched: trimmed, in capitals. */
    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return HasMany<PromoCodeUse, $this> */
    public function uses(): HasMany
    {
        return $this->hasMany(PromoCodeUse::class);
    }

    public function hasEnded(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['percent' => 'integer', 'ends_at' => 'datetime', 'max_uses' => 'integer', 'one_per_person' => 'boolean', 'months' => 'integer', 'active' => 'boolean'];
    }
}
