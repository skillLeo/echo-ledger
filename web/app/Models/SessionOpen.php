<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One user's "I have opened this recording" (since v1.3), for the unopened dot in the apps.
 *
 * @property int $id
 * @property string $capture_session_id
 * @property int $user_id
 * @property Carbon $opened_at
 */
final class SessionOpen extends Model
{
    public $timestamps = false;

    protected $fillable = ['capture_session_id', 'user_id', 'opened_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime'];
    }

    /** Records that [$userId] opened [$sessionId] now (opening again only moves the time). */
    public static function mark(string $sessionId, int $userId): void
    {
        self::query()->updateOrCreate(
            ['capture_session_id' => $sessionId, 'user_id' => $userId],
            ['opened_at' => now()],
        );
    }
}
