<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A contact or Source whose "Always … for Ahmed?" offer the person turned down (contract 2.0.0-x, CC5): never offered
 * again. The person's own, account-wide; at most CallRules::MAX_DECLINED, the oldest going.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind contact | source
 * @property string $match_key as a rule's (CallRules)
 * @property string|null $number
 * @property string|null $name
 * @property string|null $source_app
 * @property Carbon $declined_at
 */
final class CallDeclinedOffer extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'kind', 'match_key', 'number', 'name', 'source_app', 'declined_at'];

    protected function casts(): array
    {
        return ['declined_at' => 'datetime'];
    }

    /** @return array<string, string> as the API gives it */
    public function toApi(): array
    {
        return array_filter(['kind' => $this->kind, 'number' => $this->number, 'name' => $this->name, 'source_app' => $this->source_app],
            fn (?string $value): bool => $value !== null);
    }
}
