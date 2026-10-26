<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Models\Concerns\BelongsToWorkspace;
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
 * @property bool $pinned kept at the top of the library (B15)
 * @property bool $keep_forever never deleted by the retention rules (B17)
 * @property Carbon|null $media_erased_at when a deleted recording's audio and text were erased (end of the undo bin)
 * @property-read Collection<int, SessionLabel> $labels
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
    use BelongsToWorkspace, HasFactory, HasUlids, Prunable, SoftDeletes;

    protected $fillable = [
        'workspace_id', 'user_id', 'device_id', 'client_id', 'client_ref', 'source_device', 'source_app', 'title', 'language',
        'started_at', 'ended_at', 'duration_ms', 'pauses', 'speaker_names', 'status', 'pinned', 'keep_forever', 'final_provider',
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

    /** @return HasMany<SessionLabel, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(SessionLabel::class)->orderBy('label');
    }

    /** @return list<string> */
    public function labelList(): array
    {
        $labels = $this->relationLoaded('labels') ? $this->labels : $this->labels()->get();

        return $labels->pluck('label')->map(fn ($l): string => (string) $l)->values()->all();
    }

    /** @return HasOne<Recording, $this> */
    public function primaryRecording(): HasOne
    {
        return $this->hasOne(Recording::class)->where('role', RecordingRole::Primary->value);
    }

    /** @return HasMany<ShareLink, $this> */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
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
    /**
     * Tombstones whose media is gone are kept for the retention period (devices still sync the deletion), then
     * removed. Live recordings past their retention go to the undo bin first: see App\Services\RetentionPolicy.
     *
     * @return Builder<CaptureSession>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(app(SettingsRepository::class)->retentionDays());

        return static::onlyTrashed()->whereNotNull('media_erased_at')->where('deleted_at', '<', $cutoff);
    }

    protected function pruning(): void
    {
        $this->eraseMedia();
    }

    /** Hours a deleted recording waits in the undo bin before its audio and text are erased (B17). */
    public const BIN_HOURS = 48;

    /** In the undo bin: deleted, media not erased yet, and still within the 48 hours. */
    public function restorable(): bool
    {
        return $this->trashed() && $this->media_erased_at === null && $this->deleted_at !== null
            && $this->deleted_at->gt(now()->subHours(self::BIN_HOURS));
    }

    /** End of the undo bin: the audio and text go for good; the tombstone stays for syncing. */
    public function purgeMedia(): void
    {
        $this->recordings()->get()->each(fn (Recording $r) => $r->delete());
        // The session is already loaded: hand it to each transcript instead of one query per transcript (C9, N+1).
        $this->transcripts()->get()->each(fn (Transcript $t) => $t->setRelation('captureSession', $this)->delete());
        $this->forceFill(['media_erased_at' => now()])->saveQuietly();
    }

    /**
     * Delete recordings + transcripts through Eloquent so their file-cleanup events fire. The provider's own copy
     * (uploaded audio and transcript, also of a job still running) is deleted first, or queued for stt:reconcile.
     */
    public function eraseMedia(): void
    {
        app(ProviderCopyEraser::class)->eraseFor($this);
        $this->recordings()->get()->each(fn (Recording $r) => $r->delete());
        // The session is already loaded: hand it to each transcript instead of one query per transcript (C9, N+1).
        $this->transcripts()->get()->each(fn (Transcript $t) => $t->setRelation('captureSession', $this)->delete());
    }

    protected static function booted(): void
    {
        // A delete (tombstone) erases the provider's copy at once; our own encrypted copy waits 48 hours in the undo
        // bin (B17), then RetentionPolicy::emptyBin() erases it.
        static::deleting(function (CaptureSession $session): void {
            if (! $session->isForceDeleting()) {
                app(ProviderCopyEraser::class)->eraseFor($session);
            }
        });
        // Still recording or uploading: nothing finished to restore, so the partial audio goes at once.
        static::deleted(function (CaptureSession $session): void {
            if (! $session->isForceDeleting() && in_array($session->status, [SessionStatus::Recording, SessionStatus::Uploading], true)) {
                $session->purgeMedia();
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
            'speaker_names' => 'array',
            'pinned' => 'boolean',
            'keep_forever' => 'boolean',
            'media_erased_at' => 'datetime',
        ];
    }
}
