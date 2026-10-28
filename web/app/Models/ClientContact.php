<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Callers\CallerMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person at a client (since v1.2): a caller with this name, phone or email is filed under the client, and the
 * contact's language (when set) becomes the recording's language.
 *
 * @property int $id
 * @property string $client_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $phone_digits
 * @property string|null $email
 * @property string|null $language
 */
class ClientContact extends Model
{
    protected $fillable = ['client_id', 'name', 'phone', 'email', 'language'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        static::saving(function (ClientContact $contact): void {
            $contact->phone_digits = CallerMatcher::digits($contact->phone);
        });
        static::saved(fn (ClientContact $c) => $c->client?->touch());
        static::deleted(fn (ClientContact $c) => $c->client?->touch());
    }
}
