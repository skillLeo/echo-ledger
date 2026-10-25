<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider-side copy (uploaded audio and transcript) whose deletion failed; stt:reconcile retries it with backoff.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_job_id
 * @property string|null $provider_transcript_id
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $next_attempt_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ProviderDeletion extends Model
{
    protected $fillable = ['provider', 'provider_job_id', 'provider_transcript_id', 'attempts', 'last_error', 'next_attempt_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }
}
