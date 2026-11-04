<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Callers\CallerMatcher;
use App\Services\Projects\FilingRules;
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
        // A contact rule (v1.5) files through its contact: once no contact of the project has its number or email,
        // the rule goes too.
        static::updated(function (ClientContact $contact): void {
            if ($contact->wasChanged(['phone_digits', 'email', 'client_id'])) {
                foreach (array_unique([(string) $contact->getOriginal('client_id'), $contact->client_id]) as $project) {
                    FilingRules::forgetUncarried($project);
                }
            }
        });
        static::deleted(function (ClientContact $contact): void {
            FilingRules::forgetUncarried($contact->client_id);
            $contact->client?->touch();
        });
    }
}
