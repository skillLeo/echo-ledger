<?php

declare(strict_types=1);

namespace App\Models;

use App\Stt\GroqPacer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One request sent to a provider that paces its use (Groq, since v1.5): which key (a hash of it, never the key) and
 * how much audio it billed, so App\Stt\GroqPacer can count the rolling minute, hour and day. Kept two days.
 *
 * @property int $id
 * @property string $provider
 * @property string $key_id
 * @property int $audio_ms
 * @property Carbon $requested_at
 */
class ProviderRequest extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['provider', 'key_id', 'audio_ms', 'requested_at'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('requested_at', '<', now()->subHours(GroqPacer::KEEP_HOURS));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['audio_ms' => 'integer', 'requested_at' => 'datetime'];
    }
}
