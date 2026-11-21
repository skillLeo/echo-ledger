<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One health check of the Live Assist relay (plan B4, assist:relay-check, every 5 minutes, 7 days kept). No session
 * data: whether `GET https://<relay host>/healthz` answered ok, in how long, with which version, or the check's own
 * sentence when it did not.
 *
 * @property int $id
 * @property string $host
 * @property Carbon $checked_at
 * @property string $source scheduled (assist:relay-check) or manual ("Check the relay now")
 * @property bool $ok
 * @property int $ms
 * @property string|null $version
 * @property string|null $error
 */
class AssistRelayCheck extends Model
{
    /** How long the checks are kept. */
    public const KEEP_DAYS = 7;

    public $timestamps = false;

    public const SCHEDULED = 'scheduled';

    public const MANUAL = 'manual';

    protected $fillable = ['host', 'checked_at', 'source', 'ok', 'ms', 'version', 'error'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'ok' => 'boolean', 'ms' => 'integer'];
    }
}
