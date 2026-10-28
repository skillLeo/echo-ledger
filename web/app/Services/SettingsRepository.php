<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\Setting;
use App\Rules\CertificatePin;
use App\Stt\KeytermPolicy;
use App\Stt\LanguageRouter;
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
        'default_language' => null,                 // null = Auto (English, Urdu or Arabic)
        'languages.auto_more' => [],                // More languages Auto may also pick (fr, de, …)
        'languages.auto_fallback' => 'ur',          // what Auto is forced into when it hears a language it may not pick
        'assemblyai.region' => 'us',                // us | eu
        'assemblyai.streaming_model' => 'universal-3-6-pro',
        'assemblyai.async_models' => ['universal-3-5-pro', 'universal-2'],
        'elevenlabs.base_url' => 'https://api.elevenlabs.io',
        'elevenlabs.model_id' => 'scribe_v2',
        'elevenlabs.realtime_model_id' => 'scribe_v2_realtime',
        'soniox.async_model' => 'stt-async-v5',          // docs: soniox.com/docs/stt/models (2026-10)
        'accuracy.double_check' => false,                // a second engine hears the unsure lines again (DoubleCheck)
        'groq.model' => 'whisper-large-v3',              // not turbo (docs: console.groq.com/docs/model/whisper-large-v3)
        'audio.ffmpeg_path' => null,                     // null = FFMPEG_PATH, then ~/bin/ffmpeg, then the system one
        'audio.chunk_seconds' => 600,                    // Whisper chunks are cut at pauses, at most this long
        'audio.silence_db' => -40,                       // quieter than this for 0.6 s is a pause
        // Which engine transcribes each language, in order of preference; the first one with a key wins
        // (App\Stt\LanguageRouter). Automatic uses provider.final_transcript / provider.voice_note.
        'routing.final' => LanguageRouter::DEFAULT_FINAL_ROUTES,
        'routing.live' => LanguageRouter::DEFAULT_LIVE_ROUTES,
        'azure.region' => null,
        'azure.endpoint' => null,
        'azure.model' => 'MAI-Transcribe-2',         // MAI-Transcribe-2 | standard
        'whisper.base_url' => null,
        'whisper.chunk_seconds' => 600,
        'whisper.vad' => true,
        'app.pins' => [],                            // SPKI pins ("sha256/<base64>") handed to devices
        'display.timezone' => 'UTC',                 // IANA zone the admin console shows times in
        // Monthly transcription limit (whole server). Minutes = billable provider minutes (audio minutes × channels).
        'budget.monthly_minutes' => null,            // int >= 1, or null = no minutes limit
        'budget.monthly_usd' => null,                // decimal >= 0.01, or null = no US$ limit
        'markers.tags' => SessionMarkers::DEFAULT_TAGS,  // one-tap bookmark tags
        'vocabulary.keyterms' => [],                   // keyterms for every recording, after the client's
        'vocabulary.replacements' => [],               // text replacements for every final transcript
        'budget.warn_percent' => 80,                 // 1-100
        // US$ per audio hour per channel; pairs that are missing price 0 (provider list prices, 2026-10).
        'budget.prices' => [
            'assemblyai' => ['live_captions' => 0.45, 'dictation' => 0.45, 'final_transcript' => 0.26, 'voice_note' => 0.26],
            'elevenlabs' => ['live_captions' => 0.47, 'dictation' => 0.47, 'final_transcript' => 0.27, 'voice_note' => 0.27],
            'azure_mai' => ['final_transcript' => 0.10, 'voice_note' => 0.10],
            'soniox' => ['final_transcript' => 0.10, 'voice_note' => 0.10],
            'groq' => ['final_transcript' => 0.111, 'voice_note' => 0.111],
        ],
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
        $days = $this->get('retention_days', 30);

        return is_scalar($days) ? max(1, min(365, (int) $days)) : 30;
    }

    public function providerFor(SttUseCase $useCase): SttProviderKey
    {
        $value = $this->get('provider.'.$useCase->value);

        return (is_string($value) ? SttProviderKey::tryFrom($value) : null) ?? SttProviderKey::AssemblyAi;
    }

    /** The IANA time zone the admin console displays times in; falls back to UTC for anything unrecognised. */
    public function displayTimezone(): string
    {
        $zone = $this->get('display.timezone', 'UTC');

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
    }

    public function captionChannels(): string
    {
        $value = $this->get('captions.channels', 'both');

        return is_string($value) && in_array($value, ['both', 'client_only', 'off'], true) ? $value : 'both';
    }

    /** @return list<string> */
    public function pins(): array
    {
        $pins = $this->get('app.pins', []);

        // A malformed stored pin would make every pinned connection fail on the devices: hand out only valid ones.
        return is_array($pins) ? array_values(array_filter($pins, CertificatePin::isValid(...))) : [];
    }

    /** Monthly limit in billable provider minutes (audio minutes × channels), or null when none is set. */
    public function monthlyMinutesLimit(): ?int
    {
        $value = $this->get('budget.monthly_minutes');
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 ? $value : null;
    }

    /** Monthly limit in US dollars, or null when none is set. Stored JSON turns 50.0 into 50, so ints count too. */
    public function monthlyUsdLimit(): ?float
    {
        $value = $this->get('budget.monthly_usd');

        return (is_int($value) || is_float($value)) && $value >= 0.01 ? (float) $value : null;
    }

    /**
     * The bookmark tags offered during a call (docs/API.md config `markers.tags`).
     *
     * @return list<string>
     */
    public function markerTags(): array
    {
        return SessionMarkers::presets($this->get('markers.tags', SessionMarkers::DEFAULT_TAGS));
    }

    /**
     * Keyterms that apply to every recording (the client's come first).
     *
     * @return list<string>
     */
    public function globalKeyterms(): array
    {
        return KeytermPolicy::sanitize((array) $this->get('vocabulary.keyterms', []));
    }

    /**
     * Text replacements for every final transcript.
     *
     * @return list<array{from: string, to: string, match_case: bool, whole_word: bool}>
     */
    public function globalReplacements(): array
    {
        return TextReplacements::rules($this->get('vocabulary.replacements', []));
    }

    /** Share of a limit (1-100 %) at which devices and the console start warning; 80 unless set. */
    public function warnPercent(): int
    {
        $value = $this->get('budget.warn_percent', 80);

        return is_int($value) && $value >= 1 && $value <= 100 ? $value : 80;
    }

    /**
     * US$ per audio hour per channel for a provider and use case, from the console price table. A stored value that
     * is not a number, or is negative, falls back to the default; a pair the default table does not list costs 0.
     */
    public function priceFor(SttProviderKey $provider, SttUseCase $useCase): float
    {
        $stored = $this->get('budget.prices');
        $value = is_array($stored) && is_array($stored[$provider->value] ?? null) ? ($stored[$provider->value][$useCase->value] ?? null) : null;
        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return (float) $value;
        }

        $defaults = self::DEFAULTS['budget.prices'];

        return (float) ($defaults[$provider->value][$useCase->value] ?? 0.0);
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
