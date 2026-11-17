<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What one Live Assist suggestion cost, as the relay reported it (numbers and ids only). The usage ledger gets the
 * same cost as one row, so the month's totals include it.
 *
 * @property int $id
 * @property string $assist_session_id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $suggestion_id
 * @property string $provider
 * @property string $model
 * @property string $mode
 * @property string|null $plan the sold plan's key the call ran on (its session's)
 * @property string $kind
 * @property string|null $outcome
 * @property array<string, mixed>|null $latency the relay's latency stages in ms (AssistStats::STAGES)
 * @property string $cost_usd
 * @property Carbon $occurred_at
 */
final class AssistUsage extends Model
{
    protected $table = 'assist_usage';

    protected $fillable = [
        'assist_session_id', 'workspace_id', 'user_id', 'suggestion_id', 'provider', 'model', 'mode', 'plan', 'kind', 'trigger', 'outcome',
        'fallback', 'input_tokens', 'cached_tokens', 'written_tokens', 'output_tokens', 'cost_usd', 'estimated', 'latency', 'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fallback' => 'boolean', 'estimated' => 'boolean', 'latency' => 'array', 'occurred_at' => 'datetime', 'cost_usd' => 'decimal:6',
            'input_tokens' => 'integer', 'cached_tokens' => 'integer', 'written_tokens' => 'integer', 'output_tokens' => 'integer',
        ];
    }
}
