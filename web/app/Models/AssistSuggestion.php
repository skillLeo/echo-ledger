<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A suggestion the relay showed in a Live Assist session (its final words, encrypted, for the report), and what the
 * person did with it. Either may arrive first: the relay's report or the app's action.
 *
 * @property int $id
 * @property string $assist_session_id
 * @property string $suggestion_id
 * @property string|null $turn_id
 * @property string|null $kind
 * @property string|null $mode
 * @property string|null $model
 * @property string|null $replaces
 * @property string|null $text
 * @property string|null $action
 * @property Carbon|null $action_at
 * @property Carbon|null $shown_at
 */
final class AssistSuggestion extends Model
{
    public const ACTIONS = ['used', 'dismissed', 'regenerated', 'shorter', 'formal', 'translated'];

    protected $fillable = ['assist_session_id', 'suggestion_id', 'turn_id', 'kind', 'mode', 'model', 'replaces', 'text', 'action', 'action_at', 'shown_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['text' => 'encrypted', 'action_at' => 'datetime', 'shown_at' => 'datetime'];
    }
}
