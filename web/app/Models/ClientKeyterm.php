<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $client_id
 * @property string $term
 */
class ClientKeyterm extends Model
{
    protected $fillable = ['client_id', 'term'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        // Touch the client so device keyterm caches (clients.updated_at) refresh.
        static::saved(fn (ClientKeyterm $k) => $k->client?->touch());
        static::deleted(fn (ClientKeyterm $k) => $k->client?->touch());
    }
}
