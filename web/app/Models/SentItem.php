<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recording or project sent to a teammate (docs/API.md "Sending to a teammate", since v1.5). It belongs to the
 * recipient, not to a workspace: they read it from whichever workspace they are in, and the original stays in the
 * sender's workspace (`workspace_id`), which is why the relations below look past the workspace scope.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property string $kind session | project
 * @property string|null $capture_session_id
 * @property string|null $client_id
 * @property string $title
 * @property string|null $note
 * @property Carbon $sent_at
 * @property Carbon|null $saved_at
 * @property string|null $saved_id
 * @property-read User $sender
 * @property-read CaptureSession|null $captureSession
 * @property-read Client|null $client
 */
class SentItem extends Model
{
    use HasUlids;

    protected $fillable = ['workspace_id', 'from_user_id', 'to_user_id', 'kind', 'capture_session_id', 'client_id', 'title', 'note', 'sent_at', 'saved_at', 'saved_id'];

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(WorkspaceScope::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'saved_at' => 'datetime'];
    }
}
