<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A filing rule of a project (docs/API.md "Filing rules", since v1.5): `title` (the title contains the text, capitals
 * ignored), `app` (a `source_app` wire value) or `contact` (a phone number or an email; it adds that contact).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id the person whose project it is (since v1.5: each person's rules are their own)
 * @property string $client_id
 * @property string $kind
 * @property string $value
 * @property string $match_value
 * @property int|null $client_contact_id the contact a contact rule added, removed with the rule
 * @property Carbon $created_at
 * @property-read Client $client
 */
class FilingRule extends Model
{
    use BelongsToWorkspace;

    public const KINDS = ['title', 'app', 'contact'];

    protected $fillable = ['workspace_id', 'user_id', 'client_id', 'kind', 'value', 'match_value', 'client_contact_id'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<ClientContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'client_contact_id');
    }

    /** @return array{id: int, kind: string, value: string, created_at: string} */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'value' => $this->value,
            'created_at' => $this->created_at->toAtomString(),
        ];
    }
}
