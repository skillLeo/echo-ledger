<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A passkey of a console user (plan B16). `credential` is the WebAuthn credential record as JSON: the public key,
 * the signature counter and the credential id; nothing secret is stored.
 *
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string $credential_id base64url of the raw credential id
 * @property string $credential
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 */
class Passkey extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'name', 'credential_id', 'credential', 'last_used_at'];

    protected $hidden = ['credential'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }
}
