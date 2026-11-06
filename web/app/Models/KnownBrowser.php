<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A browser an account signed in to the console from (since v1.5). The browser keeps a random token in a cookie; only
 * its SHA-256 is here. A sign-in from a browser without a known token emails the account (LoginAlerts).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $label
 * @property string|null $ip
 * @property string|null $country
 * @property Carbon $last_used_at
 */
class KnownBrowser extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'label', 'ip', 'country', 'last_used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }
}
