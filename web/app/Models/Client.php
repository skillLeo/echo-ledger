<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client "folder" whose keyterms boost transcription accuracy. The apps call it a project (v1.5): it can sit
 * inside another one (`parent_id`, at most MAX_DEPTH levels; App\Services\Projects\ProjectTree keeps the tree).
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $parent_id the project it sits in; null = top level (v1.5)
 * @property string $name
 * @property string|null $color
 * @property string|null $avatar one emoji or up to two letters shown on the folder (B15)
 * @property bool $pinned listed first (B15)
 * @property int|null $retention_days this client's recordings are deleted after these days; null = the general setting (B17)
 * @property string|null $language
 * @property string|null $notes
 * @property list<array<string, mixed>>|null $replacements text replacements applied to this client's final transcripts
 * @property array<string, string>|null $speaker_names speaker label → name, remembered for this client's recordings
 * @property bool $ai_notes call notes and memory after each call (v1.4); off = no Claude request for this client
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /** Projects nest at most this many levels (docs/API.md "Projects", `too_deep`). */
    public const MAX_DEPTH = 4;

    protected $fillable = ['workspace_id', 'user_id', 'parent_id', 'name', 'color', 'avatar', 'pinned', 'language', 'retention_days', 'notes', 'speaker_names', 'replacements', 'ai_notes'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<self, $this> the project this one sits in (v1.5) */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> its subfolders (v1.5) */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<FilingRule, $this> recordings that end without a project are filed here by these (v1.5) */
    public function filingRules(): HasMany
    {
        return $this->hasMany(FilingRule::class);
    }

    /** @return HasMany<ClientFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ClientFile::class);
    }

    /** @return HasMany<ClientKeyterm, $this> */
    public function keyterms(): HasMany
    {
        return $this->hasMany(ClientKeyterm::class);
    }

    /** @return HasMany<ClientContact, $this> people at this client, to match callers (since v1.2) */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    /** @return HasMany<CaptureSession, $this> */
    public function captureSessions(): HasMany
    {
        return $this->hasMany(CaptureSession::class);
    }

    /** @return list<string> */
    public function keytermList(): array
    {
        return $this->keyterms()->orderBy('id')->pluck('term')->map(fn ($t): string => (string) $t)->values()->all();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['speaker_names' => 'array', 'replacements' => 'array', 'pinned' => 'boolean', 'retention_days' => 'integer', 'ai_notes' => 'boolean'];
    }
}
