<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Live or final transcript in the shared segment shape (docs/API.md "Segment").
 *
 * @property string $id
 * @property string $capture_session_id
 * @property TranscriptKind $kind
 * @property string $provider
 * @property TranscriptStatus $status
 * @property string|null $provider_job_id
 * @property string|null $provider_transcript_id
 * @property Carbon|null $provider_deleted_at
 * @property string|null $language
 * @property string|null $text
 * @property list<array<string, mixed>>|null $segments
 * @property array<string, mixed>|null $meta
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property-read CaptureSession $captureSession
 */
class Transcript extends Model
{
    use HasUlids, Prunable;

    protected $fillable = [
        'capture_session_id', 'kind', 'provider', 'status', 'provider_job_id', 'provider_transcript_id',
        'provider_deleted_at', 'language', 'text', 'segments', 'meta', 'error', 'attempts', 'submitted_at', 'completed_at',
    ];

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class)->withTrashed();
    }

    public function providerKey(): ?SttProviderKey
    {
        return SttProviderKey::tryFrom($this->provider);
    }

    public function preview(int $length = 160): string
    {
        return mb_strimwidth(trim((string) $this->text), 0, $length, '…');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $days = app(SettingsRepository::class)->retentionDays();

        return static::query()->where('created_at', '<', now()->subDays($days));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TranscriptKind::class,
            'status' => TranscriptStatus::class,
            'segments' => 'array',
            'meta' => 'array',
            'attempts' => 'integer',
            'provider_deleted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
