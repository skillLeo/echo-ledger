<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Live Assist session (docs/API_ASSIST.md "Sessions"): live help during a call, or Practice. Its turns, suggestions,
 * running summary, facts and report are encrypted and go with it.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $device_id
 * @property string|null $assist_prep_id
 * @property string|null $capture_session_id
 * @property string $mode
 * @property string|null $plan the sold plan's key it started on (free, pro, business, personal, …)
 * @property bool $practice
 * @property string $platform
 * @property bool $auto_saved
 * @property string $status active | ended
 * @property Carbon $started_at
 * @property Carbon|null $last_token_at
 * @property Carbon|null $ended_at
 * @property int|null $seconds_used
 * @property string $report_status none | queued | ready
 * @property array<string, mixed>|null $report
 * @property string|null $summary
 * @property list<string>|null $facts
 * @property Carbon|null $facts_at when the relay wrote the stored facts ledger
 * @property Carbon|null $summary_at when the relay wrote the stored summary
 * @property int $report_rev the relay's posts after the end, so a report made before the last one is made again
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssistSession extends Model
{
    use BelongsToWorkspace, HasUlids, SoftDeletes;

    public const PLATFORMS = ['desktop', 'android', 'ios', 'web'];

    protected $fillable = [
        'workspace_id', 'user_id', 'device_id', 'assist_prep_id', 'capture_session_id', 'mode', 'plan', 'practice', 'platform', 'auto_saved',
        'status', 'started_at', 'last_token_at', 'ended_at', 'seconds_used', 'report_status', 'report', 'summary', 'facts', 'facts_at', 'summary_at', 'report_rev',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'practice' => 'boolean',
            'auto_saved' => 'boolean',
            'started_at' => 'datetime',
            'last_token_at' => 'datetime',
            'ended_at' => 'datetime',
            'seconds_used' => 'integer',
            'report' => 'encrypted:array',
            'summary' => 'encrypted',
            'facts' => 'encrypted:array',
            'facts_at' => 'datetime',
            'summary_at' => 'datetime',
            'report_rev' => 'integer',
        ];
    }

    /** @return BelongsTo<AssistPrep, $this> */
    public function prep(): BelongsTo
    {
        return $this->belongsTo(AssistPrep::class, 'assist_prep_id')->withTrashed();
    }

    /** @return HasMany<AssistTurn, $this> */
    public function turns(): HasMany
    {
        return $this->hasMany(AssistTurn::class);
    }

    /** @return HasMany<AssistSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(AssistSuggestion::class);
    }

    public function ended(): bool
    {
        return $this->status === 'ended';
    }

    /** Seconds of assist time so far: the whole session once it ended, until now while it runs. */
    public function seconds(): int
    {
        if ($this->seconds_used !== null) {
            return $this->seconds_used;
        }

        return max(0, (int) $this->started_at->diffInSeconds($this->ended_at ?? now(), true));
    }
}
