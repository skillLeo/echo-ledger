<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A final turn of a Live Assist session: who spoke (`me` or `other`), when, and the words (encrypted).
 *
 * @property int $id
 * @property string $assist_session_id
 * @property string $client_ref
 * @property string $channel
 * @property int $start_ms
 * @property int $end_ms
 * @property string $text
 * @property string|null $language
 * @property bool $is_question
 */
final class AssistTurn extends Model
{
    protected $fillable = ['assist_session_id', 'client_ref', 'channel', 'start_ms', 'end_ms', 'text', 'language', 'is_question'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['text' => 'encrypted', 'start_ms' => 'integer', 'end_ms' => 'integer', 'is_question' => 'boolean'];
    }
}
