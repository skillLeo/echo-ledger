<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Services\ProviderCopyEraser;
use App\Services\SettingsRepository;
use Database\Factories\CaptureSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One call, meeting or voice note. Table `capture_sessions` (Laravel owns `sessions`, PLAN.md D1).
 * Soft deletes are the sync tombstones for GET /api/library?since=.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $device_id
 * @property string|null $client_id
 * @property string|null $client_ref
 * @property SourceDevice $source_device
 * @property SourceApp $source_app
 * @property string|null $title
 * @property string|null $language
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_ms
 * @property list<array{at_ms: int, paused_ms: int}>|null $pauses
 * @property-read Collection<int, SessionMarker> $markers
 * @property SessionStatus $status
 * @property string|null $final_provider
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Device|null $device
 * @property-read Client|null $client
 */
class CaptureSession extends Model
{
    /** @use HasFactory<CaptureSessionFactory> */
    use HasFactory, HasUlids, Prunable, SoftDeletes;

    protected $fillable = [
        'user_id', 'device_id', 'client_id', 'client_ref', 'source_device', 'source_app', 'title', 'language',
        'started_at', 'ended_at', 'duration_ms', 'pauses', 'status', 'final_provider',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<Recording, $this> */
    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class);
    }

    /**
     * Bookmarks on the recording's timeline, in time order.
     *
     * @return HasMany<SessionMarker, $this>
     */
    public function markers(): HasMany
    {
        return $this->hasMany(SessionMarker::class)->orderBy('at_ms');
    }

    /** @return HasOne<Recording, $this> */
    public function primaryRecording(): HasOne
    {
        return $this->hasOne(Recording::class)->where('role', RecordingRole::Primary->value);
    }

    /** @return HasMany<Transcript, $this> */
    public function transcripts(): HasMany
    {
        return $this->hasMany(Transcript::class);
    }

    /** @return HasOne<Transcript, $this> */
    public function finalTranscript(): HasOne
    {
        return $this->hasOne(Transcript::class)->where('kind', TranscriptKind::Final->value);
    }

    /** @return HasOne<Transcript, $this> */
    public function liveTranscript(): HasOne
    {
        return $this->hasOne(Transcript::class)->where('kind', TranscriptKind::Live->value);
    }

    /**
     * The user's title, or a time-free fallback. Clients show the start time beside it in the viewer's own time
     * zone; baking the server's UTC clock into the title made it disagree with that.
     */
    public function displayTitle(): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        return self::fallbackTitleFor($this->source_app);
    }

    /** The title shown for an untitled session of this app ("WhatsApp call", "Voice note", "Recording"). */
    public static function fallbackTitleFor(SourceApp $app): string
    {
        return match ($app) {
            SourceApp::VoiceNote => 'Voice note',
            SourceApp::Other => 'Recording',
            default => $app->getLabel().' call',
        };
    }

    /**
     * Sessions older than the retention window are purged with their files. Tombstones are kept for the retention
     * window counted from the deletion, so an offline device still learns about it (docs/API.md "Library & sync").
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(app(SettingsRepository::class)->retentionDays());

        return static::withTrashed()->where(fn (Builder $q) => $q
            ->where(fn (Builder $live) => $live->whereNull('deleted_at')->where('created_at', '<', $cutoff))
            ->orWhere(fn (Builder $gone) => $gone->whereNotNull('deleted_at')->where('deleted_at', '<', $cutoff)));
    }

    protected function pruning(): void
    {
        $this->eraseMedia();
    }

    /**
     * Delete recordings + transcripts through Eloquent so their file-cleanup events fire. The provider's own copy
     * (uploaded audio and transcript, also of a job still running) is deleted first, or queued for stt:reconcile.
     */
    public function eraseMedia(): void
    {
        app(ProviderCopyEraser::class)->eraseFor($this);
        $this->recordings()->get()->each(fn (Recording $r) => $r->delete());
        $this->transcripts()->get()->each(fn (Transcript $t) => $t->delete());
    }

    protected static function booted(): void
    {
        // A user/device delete (tombstone) erases audio and text immediately for privacy.
        static::deleting(function (CaptureSession $session): void {
            if (! $session->isForceDeleting()) {
                $session->eraseMedia();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_device' => SourceDevice::class,
            'source_app' => SourceApp::class,
            'status' => SessionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_ms' => 'integer',
            'pauses' => 'array',
        ];
    }
}
