<?php

declare(strict_types=1);

namespace App\Stt;

use App\Enums\RecordingRole;
use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\CaptureSession;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Stt\Drivers\AssemblyAiDriver;

/**
 * Which engine transcribes which language. The console keeps an ordered list per language (Settings → Recording →
 * "Engine per language"); the first engine on the list that has its key and supports the language is used, and when
 * it is not the first one the decision says so ("fallback"). When none has its key, the per-use-case provider is used.
 *
 * Lists are kept for English, Urdu, Arabic, `other` (the More languages) and `auto` (no language chosen).
 *
 * Support tables are from the providers' documentation (checked 2026-10-28):
 * - AssemblyAI pre-recorded: Urdu only on Universal-2 (Universal-3.5 Pro does not list it); code switching takes two
 *   codes, one of them English. Streaming: Universal-3.6 Pro lists Urdu, steered with `language_codes`.
 * - ElevenLabs Scribe v2: 90+ languages incl. Urdu (urd) and Arabic; one `language_code`, ISO 639-1 or 639-3.
 * - Soniox: one model for all languages, `language_hints` (+ strict); final transcripts only here.
 */
final class LanguageRouter
{
    /** Routing key of the More languages. */
    public const OTHER = 'other';

    /** Routing key of recordings whose language is Auto. */
    public const AUTO = 'auto';

    private const ALL = ['en', 'ur', 'ar', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'tr', 'ru'];

    /** @var array<string, list<string>> engine => languages its final transcript supports */
    public const FINAL_SUPPORT = [
        'assemblyai' => self::ALL,
        'elevenlabs' => self::ALL,
        'soniox' => self::ALL,
        'groq' => self::ALL,
        'azure_mai' => ['en'],
        'whispercpp_local' => self::ALL,
        'parakeet_local' => ['en'],
    ];

    /** @var array<string, list<string>> */
    public const DEFAULT_FINAL_ROUTES = [
        // Starting defaults until the Accuracy Lab proves otherwise: Urdu → Whisper large-v3 (Groq), English →
        // AssemblyAI (Universal-3.5 Pro), Arabic and the rest → ElevenLabs Scribe v2. A missing key moves to the next.
        'en' => ['assemblyai', 'groq', 'elevenlabs', 'soniox'],
        'ur' => ['groq', 'soniox', 'elevenlabs', 'assemblyai'],
        'ar' => ['elevenlabs', 'soniox', 'groq', 'assemblyai'],
        self::OTHER => ['elevenlabs', 'soniox', 'groq', 'assemblyai'],
        // Auto (since v1.3): most Auto recordings are mixed Urdu/English calls, which AssemblyAI can only give to
        // Universal-2; Whisper decides each side's language over the whole side (App\Stt\ChannelLanguage).
        self::AUTO => ['groq', 'assemblyai', 'soniox', 'elevenlabs'],
    ];

    /** The routing key of a language: its own list for English, Urdu and Arabic, `other` for the rest, `auto`. */
    public static function routeKey(?string $language): string
    {
        $language = LanguageChoice::normalize($language);
        if ($language === null) {
            return self::AUTO;
        }

        return in_array($language, ['en', 'ur', 'ar'], true) ? $language : self::OTHER;
    }

    public static function routeLabel(string $key): string
    {
        return match ($key) {
            self::OTHER => 'Other languages',
            self::AUTO => 'Auto',
            default => LanguageChoice::label($key),
        };
    }

    /** @return list<string> the default list of a routing key */
    public static function defaultsFor(string $key): array
    {
        return self::DEFAULT_FINAL_ROUTES[$key] ?? self::DEFAULT_FINAL_ROUTES[self::OTHER];
    }

    /**
     * Live captions follow the engine the console picked for them, then the other streaming engine: no per-language
     * list unless the admin sets one (`routing.live`).
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_LIVE_ROUTES = [];

    /** Engines the apps can stream live captions from. */
    private const LIVE_ENGINES = ['assemblyai', 'elevenlabs'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CredentialVault $vault,
    ) {}

    /** The language a recording is transcribed in: its own choice, else its client's, else the console default. */
    public function languageFor(CaptureSession $session, ?string $override = null): ?string
    {
        return LanguageChoice::normalize($override) ?? LanguageChoice::normalize($session->language)
            ?? LanguageChoice::normalize($session->client?->language) ?? LanguageChoice::normalize($this->settings->get('default_language'));
    }

    /** The engine for a final transcript (or voice note) in [$language]. */
    public function final(?string $language, SttUseCase $useCase, ?CaptureSession $session = null): RouteDecision
    {
        $language = LanguageChoice::normalize($language);
        $default = $this->settings->providerFor($useCase);
        $routes = $this->routes('routing.final', self::DEFAULT_FINAL_ROUTES);
        // Auto has its own list like every language (Auto checks the language afterwards); the engine chosen for the
        // use case is the last resort when no engine on the list has its key.
        $list = $routes[self::routeKey($language)] ?? [$default->value];
        $wanted = SttProviderKey::tryFrom($list[0] ?? '') ?? $default;
        foreach ($list as $engine) {
            $key = SttProviderKey::tryFrom($engine);
            if ($key !== null && ($language === null || $this->supportsFinal($key, $language)) && $this->configured($key) && $this->fits($key, $session)) {
                return new RouteDecision($key, $wanted, $language, $key !== $wanted);
            }
        }

        return new RouteDecision($default, $wanted, $language, $default !== $wanted);
    }

    /** The engine for live captions in [$language]. */
    public function live(?string $language): RouteDecision
    {
        $language = LanguageChoice::normalize($language);
        $default = $this->settings->providerFor(SttUseCase::LiveCaptions);
        if ($language === null) {
            return new RouteDecision($default, $default, null, false);
        }
        $list = $this->routes('routing.live', self::DEFAULT_LIVE_ROUTES)[$language]
            ?? array_values(array_unique([$default->value, ...self::LIVE_ENGINES]));
        $wanted = SttProviderKey::tryFrom($list[0] ?? '') ?? $default;
        foreach ($list as $engine) {
            $key = SttProviderKey::tryFrom($engine);
            if ($key !== null && $this->supportsLive($key, $language) && $this->configured($key)) {
                return new RouteDecision($key, $wanted, $language, $key !== $wanted);
            }
        }

        return new RouteDecision($default, $wanted, $language, $default !== $wanted);
    }

    /**
     * Engines that can transcribe [$language] here (key present, language supported), in routing order then the rest:
     * the choices for "Compare engines" and for "Re-transcribe with …".
     *
     * @return list<SttProviderKey>
     */
    public function candidates(?string $language, ?CaptureSession $session = null): array
    {
        $language = LanguageChoice::normalize($language);
        $ordered = $this->routes('routing.final', self::DEFAULT_FINAL_ROUTES)[self::routeKey($language)] ?? [];
        foreach (array_keys(self::FINAL_SUPPORT) as $engine) {
            if (! in_array($engine, $ordered, true)) {
                $ordered[] = $engine;
            }
        }
        $out = [];
        foreach ($ordered as $engine) {
            $key = SttProviderKey::tryFrom($engine);
            if ($key !== null && ($language === null || $this->supportsFinal($key, $language)) && $this->configured($key) && $this->fits($key, $session)
                && ! in_array($key, [SttProviderKey::ParakeetLocal], true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Puts [$engine] first for [$language]'s list (the winner of a comparison; a More language moves `other`). */
    public function prefer(string $language, SttProviderKey $engine): void
    {
        if (LanguageChoice::normalize($language) === null) {
            return;
        }
        $key = self::routeKey($language);
        $routes = $this->routes('routing.final', self::DEFAULT_FINAL_ROUTES);
        $routes[$key] = array_values(array_unique([$engine->value, ...($routes[$key] ?? [])]));
        $this->settings->set('routing.final', $routes);
    }

    public function supportsFinal(SttProviderKey $key, string $language): bool
    {
        return in_array($language, self::FINAL_SUPPORT[$key->value], true);
    }

    public function supportsLive(SttProviderKey $key, string $language): bool
    {
        if (! in_array($key->value, self::LIVE_ENGINES, true)) {
            return false;
        }
        if ($key === SttProviderKey::AssemblyAi && $language !== 'en') {
            return AssemblyAiDriver::streamingSupportsLanguages((string) $this->settings->get('assemblyai.streaming_model', 'universal-3-6-pro'));
        }

        return true;
    }

    /** The engine has what it needs to run here: a key (cloud) or a server address (self-hosted). */
    public function configured(SttProviderKey $key): bool
    {
        return match ($key) {
            SttProviderKey::AssemblyAi => $this->vault->has('assemblyai', 'api_key'),
            SttProviderKey::ElevenLabs => $this->vault->has('elevenlabs', 'api_key'),
            SttProviderKey::Soniox => $this->vault->has('soniox', 'api_key'),
            SttProviderKey::Groq => $this->vault->has('groq', 'api_key'),
            SttProviderKey::AzureMai => $this->vault->has('azure', 'api_key') && $this->settings->get('azure.endpoint') !== null,
            SttProviderKey::WhisperCppLocal => $this->settings->get('whisper.base_url') !== null,
            SttProviderKey::ParakeetLocal => true,
        };
    }

    /** An engine that needs the per-channel files cannot take a stereo recording that has none. */
    private function fits(SttProviderKey $key, ?CaptureSession $session): bool
    {
        if ($session === null) {
            return true;
        }
        $caps = app(SttManager::class)->driver($key)->capabilities();
        if (! $caps->needsChannelArtifacts) {
            return true;
        }
        $roles = $session->recordings()->pluck('role')->map(fn ($r) => $r instanceof RecordingRole ? $r->value : (string) $r)->all();
        $primary = $session->recordings()->where('role', RecordingRole::Primary->value)->first();

        return ($primary !== null && $primary->channels < 2) || (in_array('channel_1', $roles, true) && in_array('channel_2', $roles, true));
    }

    /**
     * The stored routing table, merged over the defaults: a language the admin never touched keeps its default list,
     * unknown engines are dropped.
     *
     * @param  array<string, list<string>>  $defaults
     * @return array<string, list<string>>
     */
    public function routes(string $key, array $defaults): array
    {
        return self::merge($this->settings->get($key), $defaults);
    }

    /**
     * @param  array<string, list<string>>  $defaults
     * @return array<string, list<string>>
     */
    public static function merge(mixed $stored, array $defaults): array
    {
        $routes = $defaults;
        if (! is_array($stored)) {
            return $routes;
        }
        $exact = [];
        $legacy = [];
        $rank = [];
        foreach ($stored as $raw => $list) {
            $raw = (string) $raw;
            $key = in_array($raw, [self::OTHER, self::AUTO], true) ? $raw : (LanguageChoice::normalize($raw) === null ? null : self::routeKey($raw));
            $clean = is_array($list) ? array_values(array_unique(array_filter(array_map('strval', $list), fn (string $e): bool => SttProviderKey::tryFrom($e) !== null))) : [];
            if ($key === null || $clean === []) {
                continue;
            }
            // A list kept under today's key wins; v1.1 keys only fill a key that has none, and for Urdu the old
            // "Urdu + English" list (what Urdu means now) wins over the old Hindi ones.
            if ($raw === $key) {
                $exact[$key] = $clean;
            } else {
                $score = str_contains($raw, 'ur') || str_contains($raw, 'ar') ? 0 : 1;
                if (! isset($rank[$key]) || $score < $rank[$key]) {
                    $legacy[$key] = $clean;
                    $rank[$key] = $score;
                }
            }
        }

        return array_merge($routes, $legacy, $exact);
    }
}
