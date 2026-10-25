<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A label on a recording ("invoice", "follow up"), used to group and filter the library (B15).
 *
 * @property int $id
 * @property string $capture_session_id
 * @property string $label
 */
class SessionLabel extends Model
{
    public $timestamps = false;

    protected $fillable = ['capture_session_id', 'label'];

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class);
    }
}
