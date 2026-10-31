<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file or pasted instructions in a client workspace (v1.4): the original is encrypted on disk, the extracted text
 * is encrypted in the database and is what goes into the brief and into Ask.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $client_id
 * @property string $name
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $path
 * @property string $status ready | extracting | failed
 * @property string|null $error
 * @property int|null $pages
 * @property string|null $text
 * @property Carbon $created_at
 */
class ClientFile extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $fillable = ['workspace_id', 'user_id', 'client_id', 'name', 'mime', 'size_bytes', 'path', 'status', 'error', 'pages', 'text'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['text' => 'encrypted', 'size_bytes' => 'integer', 'pages' => 'integer'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'pages' => $this->pages,
            'status' => $this->status,
            'error' => $this->error,
            'instructions' => $this->path === null,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
