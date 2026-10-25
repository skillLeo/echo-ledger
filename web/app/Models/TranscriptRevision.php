<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One saved state of an edited transcript. `original` is the provider's text, kept from the first edit on so it can
 * always be restored; `edit`, `replace` and `restore` follow each change.
 *
 * @property string $id
 * @property string $transcript_id
 * @property string $source
 * @property list<array<string, mixed>> $segments
 * @property string|null $text
 * @property string|null $author
 * @property Carbon $created_at
 * @property-read Transcript $transcript
 */
class TranscriptRevision extends Model
{
    use HasUlids;

    public const ORIGINAL = 'original';

    public const EDIT = 'edit';

    public const REPLACE = 'replace';

    public const RESTORE = 'restore';

    protected $fillable = ['transcript_id', 'source', 'segments', 'text', 'author'];

    /** @return BelongsTo<Transcript, $this> */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'author' => $this->author,
            'created_at' => $this->created_at->toAtomString(),
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['segments' => 'array'];
    }
}
