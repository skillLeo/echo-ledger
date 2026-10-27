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
 * it is not the first one the decision says so ("fallback"). Automatic keeps the per-use-case provider.
 *
 * Support tables are from the providers' documentation (checked 2026-10-27):
 * - AssemblyAI pre-recorded: Universal-2 covers Urdu and Hindi; Universal-3.5 Pro covers Hindi, not Urdu; code
 *   switching takes two codes, one of them English. Streaming: Universal-3.6 Pro covers Urdu and Hindi with code
 *   switching (`language_codes`); the English and the six-language multilingual models do not.
 * - ElevenLabs Scribe v2: 90+ languages incl. Urdu (urd) and Hindi (hin); one `language_code`, ISO 639-1 or 639-3.
 * - Soniox: Urdu (ur) and Hindi (hi) through `language_hints`, one model for all languages; final transcripts only
 *   here (the apps cannot stream to it).
 */
final class LanguageRouter
{
    private const ALL = ['en', 'ur', 'en+ur', 'hi', 'en+hi'];

    /** @var array<string, list<string>> engine => languages its final transcript supports */
    public const FINAL_SUPPORT = [
        'assemblyai' => self::ALL,
        'elevenlabs' => self::ALL,
        'soniox' => self::ALL,
        'azure_mai' => ['en'],
        'whispercpp_local' => self::ALL,
        'parakeet_local' => ['en'],
    ];

    /** @var array<string, list<string>> */
    public const DEFAULT_FINAL_ROUTES = [
        'en' => ['assemblyai', 'elevenlabs', 'soniox'],
        'ur' => ['elevenlabs', 'soniox', 'assemblyai'],
        'en+ur' => ['elevenlabs', 'soniox', 'assemblyai'],
        'hi' => ['assemblyai', 'elevenlabs', 'soniox'],
        'en+hi' => ['assemblyai', 'soniox', 'elevenlabs'],
    ];

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
        if ($language === null) {
            return new RouteDecision($default, $default, null, false);
        }
        $list = $this->routes('routing.final', self::DEFAULT_FINAL_ROUTES)[$language] ?? [$default->value];
        $wanted = SttProviderKey::tryFrom($list[0] ?? '') ?? $default;
        foreach ($list as $engine) {
            $key = SttProviderKey::tryFrom($engine);
            if ($key !== null && $this->supportsFinal($key, $language) && $this->configured($key) && $this->fits($key, $session)) {
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
        $ordered = $language === null ? [] : ($this->routes('routing.final', self::DEFAULT_FINAL_ROUTES)[$language] ?? []);
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

    /** Puts [$engine] first for [$language] (the winner of a comparison). */
    public function prefer(string $language, SttProviderKey $engine): void
    {
        $language = LanguageChoice::normalize($language);
        if ($language === null) {
            return;
        }
        $routes = $this->routes('routing.final', self::DEFAULT_FINAL_ROUTES);
        $routes[$language] = array_values(array_unique([$engine->value, ...($routes[$language] ?? [])]));
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
        if (is_array($stored)) {
            foreach ($stored as $language => $list) {
                $language = LanguageChoice::normalize((string) $language);
                if ($language === null || ! is_array($list)) {
                    continue;
                }
                $clean = array_values(array_unique(array_filter(array_map('strval', $list), fn (string $e): bool => SttProviderKey::tryFrom($e) !== null)));
                if ($clean !== []) {
                    $routes[$language] = $clean;
                }
            }
        }

        return $routes;
    }
}
