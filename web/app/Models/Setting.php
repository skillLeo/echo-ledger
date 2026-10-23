<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Non-secret settings (retention, provider per use-case, provider options). Secrets live in Credential.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
