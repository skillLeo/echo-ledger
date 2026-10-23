<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Provider secret, encrypted at rest with the app key (`encrypted` cast). Read via App\Services\CredentialVault.
 * Never serialized: `value` is hidden.
 *
 * @property int $id
 * @property string $provider
 * @property string $key
 * @property string $value
 * @property int|null $updated_by
 * @property Carbon $updated_at
 */
class Credential extends Model
{
    protected $fillable = ['provider', 'key', 'value', 'updated_by'];

    protected $hidden = ['value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
