<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Non-secret settings with defaults. Values are JSON in the `settings` table; cached per request/job.
 */
final class SettingsRepository
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'retention_days' => 30,
        'provider.live_captions' => 'assemblyai',
        'provider.dictation' => 'assemblyai',
        'provider.final_transcript' => 'assemblyai',
        'provider.voice_note' => 'assemblyai',
        'captions.channels' => 'both',              // both | client_only | off
        'default_language' => null,                 // null = auto-detect
        'assemblyai.region' => 'us',                // us | eu
        'assemblyai.streaming_model' => 'universal-3-6-pro',
        'assemblyai.async_models' => ['universal-3-5-pro', 'universal-2'],
        'elevenlabs.base_url' => 'https://api.elevenlabs.io',
        'elevenlabs.model_id' => 'scribe_v2',
        'elevenlabs.realtime_model_id' => 'scribe_v2_realtime',
        'azure.region' => null,
        'azure.endpoint' => null,
        'azure.model' => 'MAI-Transcribe-2',         // MAI-Transcribe-2 | standard
        'whisper.base_url' => null,
        'whisper.chunk_seconds' => 600,
        'whisper.vad' => true,
        'app.pins' => [],                            // SPKI pins ("sha256/<base64>") handed to devices
        'display.timezone' => 'UTC',                 // IANA zone the admin console shows times in
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = [];
        try {
            if (Schema::hasTable('settings')) {
                $stored = Setting::query()->pluck('value', 'key')->all();
            }
        } catch (Throwable) {
            // During install/migrations the table may not exist yet; defaults apply.
        }

        return $this->cache = array_merge(self::DEFAULTS, $stored);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->cache = null;
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->cache = null;
    }

    public function retentionDays(): int
    {
        return max(1, min(365, (int) $this->get('retention_days', 30)));
    }

    public function providerFor(SttUseCase $useCase): SttProviderKey
    {
        return SttProviderKey::tryFrom((string) $this->get('provider.'.$useCase->value)) ?? SttProviderKey::AssemblyAi;
    }

    /** The IANA time zone the admin console displays times in; falls back to UTC for anything unrecognised. */
    public function displayTimezone(): string
    {
        $zone = (string) $this->get('display.timezone', 'UTC');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
    }

    public function captionChannels(): string
    {
        $value = (string) $this->get('captions.channels', 'both');

        return in_array($value, ['both', 'client_only', 'off'], true) ? $value : 'both';
    }

    /** @return list<string> */
    public function pins(): array
    {
        $pins = $this->get('app.pins', []);

        return is_array($pins) ? array_values(array_filter(array_map('strval', $pins))) : [];
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
