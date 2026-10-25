<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client "folder" whose keyterms boost transcription accuracy.
 *
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property string|null $color
 * @property string|null $avatar one emoji or up to two letters shown on the folder (B15)
 * @property bool $pinned listed first (B15)
 * @property string|null $language
 * @property string|null $notes
 * @property list<array<string, mixed>>|null $replacements text replacements applied to this client's final transcripts
 * @property array<string, string>|null $speaker_names speaker label → name, remembered for this client's recordings
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['user_id', 'name', 'color', 'avatar', 'pinned', 'language', 'notes', 'speaker_names', 'replacements'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ClientKeyterm, $this> */
    public function keyterms(): HasMany
    {
        return $this->hasMany(ClientKeyterm::class);
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
        return ['speaker_names' => 'array', 'replacements' => 'array', 'pinned' => 'boolean'];
    }
}
