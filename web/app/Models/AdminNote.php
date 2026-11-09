<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff note about a customer (since v1.6, P2.2): on the admin panel only, never shown to the person.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $author_id
 * @property string $body
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read User|null $author
 */
class AdminNote extends Model
{
    protected $fillable = ['user_id', 'author_id', 'body'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
