<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One billable (or explicitly non-billable) use of a speech provider (docs/API.md "Usage and monthly limit").
 * Rows are written by POST /api/usage (source `device`) and when a final transcript completes (source `server`).
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $device_id
 * @property string|null $capture_session_id
 * @property string|null $transcript_id
 * @property string $source
 * @property string $report_id
 * @property string $use_case
 * @property string $provider
 * @property string|null $model
 * @property int $duration_ms
 * @property int $channels
 * @property int $billed_ms
 * @property string $unit_price_usd
 * @property string $cost_usd
 * @property bool $billable
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UsageLedgerEntry extends Model
{
    use BelongsToWorkspace, HasUlids, Prunable;

    public const SOURCE_DEVICE = 'device';

    public const SOURCE_SERVER = 'server';

    /** Rows are kept a little over a year, long enough to compare a month with the same month last year. */
    public const RETENTION_DAYS = 400;

    protected $table = 'usage_ledger';

    protected $fillable = [
        'workspace_id', 'user_id', 'device_id', 'capture_session_id', 'transcript_id', 'source', 'report_id', 'use_case', 'provider',
        'model', 'duration_ms', 'channels', 'billed_ms', 'unit_price_usd', 'cost_usd', 'billable', 'occurred_at', 'client_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class)->withTrashed();
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'channels' => 'integer',
            'billed_ms' => 'integer',
            'unit_price_usd' => 'decimal:4',
            'cost_usd' => 'decimal:6',
            'billable' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }
}
