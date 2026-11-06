<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A screen moment (v1.5, docs/API.md "Screen moments"): what was on the shared screen at `at_ms` of a recording. The
 * picture and its 480 px thumbnail are encrypted on the recordings disk (App\Services\Screens\ScreenStore); they go
 * with the recording (retention, the undo bin, delete).
 *
 * @property string $id
 * @property int $workspace_id
 * @property string $capture_session_id
 * @property int $at_ms
 * @property int $width
 * @property int $height
 * @property string $trigger change | words | manual
 * @property string $sha256
 * @property string $mime image/webp | image/png | image/jpeg
 * @property string|null $image_path
 * @property string|null $thumb_path
 * @property int $bytes
 * @property string|null $ocr_text the text Claude read on the picture
 * @property string|null $description one sentence about what the screen shows
 * @property string|null $search_text
 * @property Carbon|null $described_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read CaptureSession $captureSession
 */
class Screen extends Model
{
    use BelongsToWorkspace, HasUlids;

    /** Why the desktop kept it: the picture changed and settled, someone said "this page", or the person pressed the button. */
    public const TRIGGERS = ['change', 'words', 'manual'];

    /** File extension of each stored picture type (the meeting pack's images/). */
    public const EXTENSIONS = ['image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

    protected $fillable = [
        'workspace_id', 'capture_session_id', 'at_ms', 'width', 'height', 'trigger', 'sha256', 'mime', 'image_path', 'thumb_path',
        'bytes', 'ocr_text', 'description', 'search_text', 'described_at',
    ];

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class)->withTrashed();
    }

    public function extension(): string
    {
        return self::EXTENSIONS[$this->mime] ?? 'webp';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'at_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'described_at' => 'datetime',
        ];
    }
}
