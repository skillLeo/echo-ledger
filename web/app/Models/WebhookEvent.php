<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inbound provider callback, unique per (provider, idempotency_key). Payload is encrypted (may contain transcript text).
 *
 * @property int $id
 * @property string $provider
 * @property string $idempotency_key
 * @property string|null $transcript_id
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
class WebhookEvent extends Model
{
    use Prunable;

    protected $fillable = ['provider', 'idempotency_key', 'transcript_id', 'payload', 'status', 'processed_at', 'error'];

    protected $hidden = ['payload'];

    /** @return BelongsTo<Transcript, $this> */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }

    public function prunable(): Builder
    {
        $days = app(SettingsRepository::class)->retentionDays();

        return static::query()->where('created_at', '<', now()->subDays($days));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'processed_at' => 'datetime'];
    }
}
