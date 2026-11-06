<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client as OAuthClient;

/**
 * An app a person allowed to read their recordings through the connector (since v1.5, docs/API.md "Claude and
 * ChatGPT connector"): Claude, ChatGPT or another MCP client, registered as a Passport client. Made when its first
 * token is issued; Profile → Connected apps lists it with Disconnect (App\Services\Connector\ConnectedApps). A
 * disconnected row stays, marked, so the app keeps its registration and can be connected again.
 *
 * @property int $id
 * @property int $user_id
 * @property string $client_id
 * @property Carbon|null $last_used_at
 * @property Carbon|null $disconnected_at
 * @property Carbon $created_at
 * @property-read OAuthClient|null $client
 */
class ConnectedApp extends Model
{
    protected $fillable = ['user_id', 'client_id', 'last_used_at'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<OAuthClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'client_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->whereNull('disconnected_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'disconnected_at' => 'datetime'];
    }
}
