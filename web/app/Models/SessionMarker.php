<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bookmark on a recording's timeline: `at_ms` is recording time (paused time excluded), so it lines up with the
 * transcript. `source` is `device` (set during the call) or `web` (added in the console).
 *
 * @property string $id
 * @property string $capture_session_id
 * @property int $at_ms
 * @property string|null $tag
 * @property string|null $note
 * @property string $source
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read CaptureSession $captureSession
 */
class SessionMarker extends Model
{
    use HasUlids;

    /**
     * Changing a marker changes what the library shows, so incremental sync must pick the session up again.
     *
     * @var list<string>
     */
    protected $touches = ['captureSession'];

    protected $fillable = ['capture_session_id', 'at_ms', 'tag', 'note', 'source'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['at_ms' => 'integer'];
    }

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class);
    }

    /** @return array{id: string, at_ms: int, tag: string|null, note: string|null, source: string, created_at: string} */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'at_ms' => $this->at_ms,
            'tag' => $this->tag,
            'note' => $this->note,
            'source' => $this->source,
            'created_at' => $this->created_at->utc()->toAtomString(),
        ];
    }
}
