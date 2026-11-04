<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file sent through a request link (v1.5), while its chunks arrive and after: a `recording` (its chunks are the
 * recording's own) or a `file` for the project's Files (chunks counted here, parts under `request-parts/{id}`).
 *
 * @property string $id
 * @property string $request_link_id
 * @property string $kind recording | file
 * @property string $name
 * @property int $size_bytes
 * @property string $sha256
 * @property string $status uploading | ready | refused | failed
 * @property string|null $error
 * @property string|null $recording_id
 * @property int $chunk_size_bytes
 * @property int $total_chunks
 * @property list<int>|null $received_chunks
 * @property string|null $client_file_id
 * @property-read RequestLink $link
 * @property-read Recording|null $recording
 */
class RequestUpload extends Model
{
    use HasUlids;

    protected $fillable = ['request_link_id', 'kind', 'name', 'size_bytes', 'sha256', 'status', 'error', 'recording_id', 'chunk_size_bytes', 'total_chunks', 'received_chunks', 'client_file_id'];

    /** @return BelongsTo<RequestLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(RequestLink::class, 'request_link_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<Recording, $this> */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }

    public function partsDirectory(): string
    {
        return 'request-parts/'.$this->id;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'chunk_size_bytes' => 'integer', 'total_chunks' => 'integer', 'received_chunks' => 'array'];
    }
}
