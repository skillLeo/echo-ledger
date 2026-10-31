<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of a client's memory (v1.4): a decision, an open question, a change or a scope line, dated and citing the
 * call (with its moment), file or person it comes from. People can edit, pin or remove lines.
 *
 * @property string $id
 * @property string $client_id
 * @property string $kind scope | decision | question | change
 * @property string $text
 * @property Carbon|null $happened_on
 * @property bool $pinned
 * @property string $source_type call | file | user
 * @property string|null $source_id
 * @property int|null $at_ms
 * @property Carbon $created_at
 */
class ClientMemoryItem extends Model
{
    use BelongsToWorkspace, HasUlids;

    public const KINDS = ['scope', 'decision', 'question', 'change'];

    protected $fillable = ['workspace_id', 'client_id', 'kind', 'text', 'happened_on', 'pinned', 'source_type', 'source_id', 'at_ms'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['happened_on' => 'date', 'pinned' => 'boolean', 'at_ms' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'text' => $this->text,
            'happened_on' => $this->happened_on?->toDateString(),
            'pinned' => $this->pinned,
            'source' => ['type' => $this->source_type, 'id' => $this->source_id, 'at_ms' => $this->at_ms],
        ];
    }
}
