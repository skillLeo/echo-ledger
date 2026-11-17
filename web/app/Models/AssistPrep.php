<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Live Assist prep (docs/API_ASSIST.md "Preps"): what a meeting is about, set before it. Every text is encrypted.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $client_id the project (`project_id` in the API)
 * @property string $meeting_type
 * @property string|null $title
 * @property string|null $counterpart
 * @property string|null $company
 * @property string|null $goals
 * @property string|null $done
 * @property string|null $not_done
 * @property string|null $to_discuss
 * @property string|null $instructions
 * @property string $hint_level
 * @property string $mode
 * @property string $language_help
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $armed_until
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssistPrep extends Model
{
    use BelongsToWorkspace, HasUlids, SoftDeletes;

    public const MEETING_TYPES = ['interview', 'boss', 'existing_client', 'new_client', 'general'];

    public const HINT_LEVELS = ['keywords', 'outline', 'full'];

    public const MODES = ['fast', 'balanced', 'deep', 'max'];

    public const LANGUAGE_HELP = ['off', 'urdu', 'roman_urdu', 'arabic'];

    public const STATUSES = ['draft', 'scheduled', 'armed', 'done', 'cancelled'];

    /** The free-text fields, each at most this long. */
    public const TEXT_FIELDS = ['title', 'counterpart', 'company', 'goals', 'done', 'not_done', 'to_discuss', 'instructions'];

    public const MAX_TEXT = 4000;

    /** An armed prep waits this long for its meeting, then it is cancelled. */
    public const ARMED_MINUTES = 10;

    protected $fillable = [
        'workspace_id', 'user_id', 'client_id', 'meeting_type', 'title', 'counterpart', 'company', 'goals', 'done', 'not_done',
        'to_discuss', 'instructions', 'hint_level', 'mode', 'language_help', 'scheduled_at', 'armed_until', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::TEXT_FIELDS, 'encrypted'),
            'scheduled_at' => 'datetime',
            'armed_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> the project (`project_id` in the API) */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return BelongsToMany<ClientFile, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(ClientFile::class, 'assist_prep_documents', 'assist_prep_id', 'client_file_id')->withPivot('tokens')->withTimestamps();
    }

    /** An armed prep whose wait is over reads as cancelled. */
    public function currentStatus(): string
    {
        return $this->status === 'armed' && $this->armed_until !== null && $this->armed_until->isPast() ? 'cancelled' : $this->status;
    }
}
