<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\Setting;
use App\Rules\CertificatePin;
use App\Stt\Drivers\GeminiDriver;
use App\Stt\GroqPacer;
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
        // Live Translate (plan B): the phone streams to Soniox with a temporary key; nothing passes through here.
        'live_translate.model' => 'stt-rt-v5',
        'live_translate.monthly_usd' => 10.0,           // Live Translate's own monthly cap (warn at 80 %, pause at 100 %)
        'live_translate.game_target' => 'en',
        'live_translate.game_spoken' => ['ar', 'en'],
        'live_translate.game_speed' => 'fastest',       // fastest | balanced | accurate
        'live_translate.watch_target' => 'ur',
        'live_translate.watch_speed' => 'balanced',
        'claude.model' => 'claude-sonnet-5-5',
        'views.short_model' => null,                     // since v1.5: the views model for notes of 3 minutes or less (null = claude.model)           // Show as (Roman Urdu / اردو / English views), plan A3
        'views.default_ur' => 'roman',                   // the view Urdu and mixed calls open in (plan A3)
        'views.default_en' => 'original',                // English calls (the Urdu translation is one tap away)
        'views.default_ar' => 'original',                // Arabic calls
        'unsure.style' => 'brackets',                   // unsure words in copies and exports: [?word] | word(?) | off (plan A4)
        'unsure.threshold' => 0.5,                      // a word scored below this is unsure
        'unsure.note' => true,                          // the "Note: [?word] = …" line on copies and exports
        'accuracy.double_check' => false,                // a second engine hears the unsure lines again (DoubleCheck)
        'accuracy.double_check_short' => true,           // since v1.5: a second engine hears every note up to 3 minutes
        'arabic.dialect' => null,                         // since v1.5: gulf | egyptian | levantine | msa (a hint)
        'accuracy.double_check_clients' => true,         // since v1.4: always for recordings filed under a client
        'client_ai.monthly_usd' => 5.0,                  // since v1.4: Claude spend of client workspaces per month
        'screens.describe' => true,                      // since v1.5: Claude Haiku reads each screen moment's text and describes it
        'groq.model' => 'whisper-large-v3',              // not turbo (docs: console.groq.com/docs/model/whisper-large-v3)
        'groq.plan' => GroqPacer::DEFAULT_PLAN,          // since v1.5: free | developer, the limits Groq requests are paced under
        'gemini.model' => GeminiDriver::DEFAULT_MODEL,   // since v1.5 (docs: aistudio.google.com/docs/models, 2026-11-04)
        'audio.ffmpeg_path' => null,                     // null = FFMPEG_PATH, then ~/bin/ffmpeg, then the system one
        'audio.chunk_seconds' => 600,                    // Whisper chunks are cut at pauses, at most this long
        'audio.silence_db' => -40,                       // quieter than this for 0.6 s is a pause
        // Which engine transcribes each language, in order of preference; the first one with a key wins
        // (App\Stt\LanguageRouter). Automatic uses provider.final_transcript / provider.voice_note.
        'routing.final' => LanguageRouter::DEFAULT_FINAL_ROUTES,
        'routing.live' => LanguageRouter::DEFAULT_LIVE_ROUTES,
        // Since v1.5: an Urdu or Auto recording waits this long for its engine before a lower one takes it (EngineWait).
        'routing.urdu_wait_hours' => EngineWait::DEFAULT_HOURS,
        'azure.region' => null,
        'azure.endpoint' => null,
        'azure.model' => 'MAI-Transcribe-2',         // MAI-Transcribe-2 | standard
        'whisper.base_url' => null,
        'whisper.chunk_seconds' => 600,
        'whisper.vad' => true,
        'app.pins' => [],                            // SPKI pins ("sha256/<base64>") handed to devices
        'display.timezone' => 'UTC',                 // IANA zone the admin console shows times in
        // Since v1.5: who may make an account on the console's sign-up page (App\Services\Accounts\SignUp).
        'signup.mode' => 'nobody',                   // nobody | anyone | invited
        'signup.allowed_domains' => [],              // since v1.6: empty = any domain (throwaway addresses never)
        'signup.per_ip_day' => 5,                    // since v1.6: new accounts a day from one IP address
        'upgrade.payment_instructions' => null,      // since v1.6: shown after "Request upgrade" (System → Plans)
        'console.members' => true,                   // since v1.5: members and viewers get the small read-only console
        // Since v1.5: the SMTP server emails go through (the password is in the credential vault, as `mail.password`).
        // No host = the mailer of the server's .env file.
        'mail.host' => null,
        'mail.port' => 587,
        'mail.encryption' => 'tls',                  // tls (STARTTLS) | ssl | none
        'mail.username' => null,
        'mail.from_address' => null,
        'mail.from_name' => 'EchoLedger',
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
            'soniox' => ['final_transcript' => 0.10, 'voice_note' => 0.10, 'game_translate' => 0.18, 'watch_translate' => 0.18],
            'groq' => ['final_transcript' => 0.111, 'voice_note' => 0.111],
            // Since v1.5: the audio input of Gemini 3.1 Pro (32 tokens a second at US$2 per million); the answer's
            // tokens come on top and are not in this price.
            'gemini' => ['final_transcript' => 0.23, 'voice_note' => 0.23],
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
