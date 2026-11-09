<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A 6-digit code sent by email to sign in or sign up (since v1.6, P1, docs/API.md "Sign-up and sign-in with an email
 * code"). Only a keyed hash of the code is stored; a new code replaces the old one. Right for a new address, it gives a
 * one-time sign-up token (also hashed) that makes the account.
 *
 * @property int $id
 * @property string $email
 * @property string $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $used_at
 * @property string|null $sign_up_token_hash
 * @property Carbon|null $sign_up_token_expires_at
 * @property string|null $ip
 * @property Carbon $created_at
 */
class EmailCode extends Model
{
    use MassPrunable;

    protected $fillable = ['email', 'purpose', 'code_hash', 'attempts', 'expires_at', 'verified_at', 'used_at', 'sign_up_token_hash', 'sign_up_token_expires_at', 'ip'];

    protected $hidden = ['code_hash', 'sign_up_token_hash'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDay());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
            'sign_up_token_expires_at' => 'datetime',
        ];
    }
}
