<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A file in the recordings bucket whose row is gone but whose delete failed; stt:reconcile retries it with backoff.
 *
 * @property int $id
 * @property string $path
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $next_attempt_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class StorageDeletion extends Model
{
    protected $fillable = ['path', 'attempts', 'last_error', 'next_attempt_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }
}
