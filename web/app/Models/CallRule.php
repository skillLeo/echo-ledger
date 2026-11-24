<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a person's call rules (contract 2.0.0-w, docs/API.md "Call rules"): for a contact (a number, or a name when
 * there is none), a contact group or a Source, what happens to the call. The person's own, account-wide.
 *
 * @property string $id
 * @property int $user_id
 * @property string $kind contact | group | source
 * @property string $match_key what a call is matched by (CallRules::matchKey)
 * @property string|null $number
 * @property string|null $name
 * @property string|null $group_key
 * @property string|null $source_app
 * @property string $action ask | always_transcribe | always_audio | dont_record
 */
final class CallRule extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'kind', 'match_key', 'number', 'name', 'group_key', 'source_app', 'action'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> the rule as the API gives it */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            ...match ($this->kind) {
                'contact' => ['number' => $this->number, 'name' => $this->name],
                'group' => ['group' => $this->group_key],
                default => ['source_app' => $this->source_app],
            },
            'action' => $this->action,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
