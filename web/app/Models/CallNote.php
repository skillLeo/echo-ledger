<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The short notes of one kept call, voice note or import of a client (v1.4): only the sections with content, every
 * bullet tied to the moment of the transcript it comes from, unsure ones marked "verify".
 *
 * @property string $id
 * @property string $client_id
 * @property string $capture_session_id
 * @property string $status pending | ready | failed | skipped
 * @property string|null $error
 * @property array<string, list<array<string, mixed>>>|null $sections
 * @property string $language
 * @property string|null $model
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CallNote extends Model
{
    use BelongsToWorkspace, HasUlids;

    /** The sections, in the order they are shown, with their headings. */
    public const SECTIONS = [
        'asked_for' => 'Asked for',
        'changes' => 'Changes',
        'new_items' => 'New, not in the brief',
        'decisions' => 'Decisions',
        'to_confirm' => 'To confirm with the client',
        'numbers' => 'Deadlines, prices, numbers',
        'my_actions' => 'My action items',
    ];

    protected $fillable = ['workspace_id', 'client_id', 'capture_session_id', 'status', 'error', 'sections', 'language', 'model'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sections' => 'array'];
    }

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $sections = [];
        foreach (self::SECTIONS as $key => $heading) {
            $items = $this->sections[$key] ?? [];
            if ($items !== []) {
                $sections[] = ['key' => $key, 'heading' => $heading, 'items' => $items];
            }
        }

        return [
            'id' => $this->id,
            'session_id' => $this->capture_session_id,
            'status' => $this->status,
            'error' => $this->error,
            'language' => $this->language,
            'sections' => $sections,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
