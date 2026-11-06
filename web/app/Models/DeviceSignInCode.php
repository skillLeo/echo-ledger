<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time code of the apps' browser sign-in (since v1.5, docs/API.md "Signing in with an account"): made when the
 * person approves "Sign in EchoLedger on <name>?", valid 5 minutes, redeemed once with the PKCE verifier whose
 * SHA-256 is `code_challenge`. Only the SHA-256 of the code is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property int $workspace_id
 * @property string $code_hash
 * @property string $code_challenge
 * @property string $name
 * @property string $platform
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class DeviceSignInCode extends Model
{
    use MassPrunable;

    protected $fillable = ['user_id', 'workspace_id', 'code_hash', 'code_challenge', 'name', 'platform', 'expires_at', 'used_at'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
