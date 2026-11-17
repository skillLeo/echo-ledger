<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of a person's call rules (contract 2.1.0-a): for a contact, a group of contacts or a source app, what happens
 * after (or instead of) a call. The person's, not the workspace's; shared by all their devices.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind contact | group | source
 * @property string $key the contact's key (tel:, wa:, app:), the group or the source
 * @property string|null $name
 * @property string $action transcribe | keep_audio | dont_record | ask
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CallRule extends Model
{
    protected $fillable = ['user_id', 'kind', 'key', 'name', 'action'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
