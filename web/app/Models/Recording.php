<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecordingRole;
use App\Enums\UploadStatus;
use App\Services\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One uploaded audio artifact of a session (PLAN.md D5). Stored encrypted on the `recordings` disk.
 *
 * @property string $id
 * @property string $capture_session_id
 * @property RecordingRole $role
 * @property string $mime
 * @property string|null $codec
 * @property int $channels
 * @property int|null $sample_rate
 * @property int|null $duration_ms
 * @property int $declared_size_bytes
 * @property string $sha256
 * @property int $chunk_size_bytes
 * @property int $total_chunks
 * @property list<int> $received_chunks
 * @property UploadStatus $upload_status
 * @property string|null $stored_path
 * @property int|null $stored_size_bytes
 * @property string $key_id
 * @property Carbon|null $assembled_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property-read CaptureSession $captureSession
 */
class Recording extends Model
{
    use HasUlids, Prunable;

    protected $fillable = [
        'capture_session_id', 'role', 'mime', 'codec', 'channels', 'sample_rate', 'duration_ms',
        'declared_size_bytes', 'sha256', 'chunk_size_bytes', 'total_chunks', 'received_chunks', 'upload_status',
        'stored_path', 'stored_size_bytes', 'key_id', 'assembled_at', 'error',
    ];

    /** @return BelongsTo<CaptureSession, $this> */
    public function captureSession(): BelongsTo
    {
        return $this->belongsTo(CaptureSession::class)->withTrashed();
    }

    /** Directory holding the raw upload parts (relative to the recordings disk). */
    public function partsDirectory(): string
    {
        return 'parts/'.$this->id;
    }

    public function isReady(): bool
    {
        return $this->upload_status === UploadStatus::Ready && $this->stored_path !== null;
    }

    public function prunable(): Builder
    {
        $days = app(SettingsRepository::class)->retentionDays();

        return static::query()->where('created_at', '<', now()->subDays($days));
    }

    public function deleteFiles(): void
    {
        $disk = Storage::disk('recordings');
        try {
            if ($this->stored_path !== null && $disk->exists($this->stored_path)) {
                $disk->delete($this->stored_path);
            }
            if ($disk->directoryExists($this->partsDirectory())) {
                $disk->deleteDirectory($this->partsDirectory());
            }
        } catch (Throwable $e) {
            report($e); // missing files must never block deletion or pruning
        }
    }

    protected static function booted(): void
    {
        static::deleted(fn (Recording $recording) => $recording->deleteFiles());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => RecordingRole::class,
            'upload_status' => UploadStatus::class,
            'received_chunks' => 'array',
            'channels' => 'integer',
            'sample_rate' => 'integer',
            'duration_ms' => 'integer',
            'declared_size_bytes' => 'integer',
            'chunk_size_bytes' => 'integer',
            'total_chunks' => 'integer',
            'stored_size_bytes' => 'integer',
            'assembled_at' => 'datetime',
        ];
    }
}
