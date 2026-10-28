<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Settings as a portable JSON file (plan B19). Only the non-secret console settings travel; provider keys and webhook
 * secrets live in the credential vault and are never read here. Reading a file keeps known keys only; the Settings
 * page then validates the values like a form submit.
 */
final class SettingsTransfer
{
    public const FORMAT = 'echoledger-console-settings';

    public const VERSION = 1;

    public const MAX_BYTES = 262144;

    /** The settings keys that are exported and imported: everything the Settings page edits. */
    public const PORTABLE = [
        'retention_days',
        'provider.live_captions',
        'provider.dictation',
        'provider.final_transcript',
        'provider.voice_note',
        'routing.final',
        'captions.channels',
        'default_language',
        'languages.auto_more',
        'languages.auto_fallback',
        'accuracy.double_check',
        'claude.model',
        'views.default',
        'display.timezone',
        'vocabulary.keyterms',
        'vocabulary.replacements',
        'markers.tags',
        'assemblyai.region',
        'assemblyai.streaming_model',
        'assemblyai.async_models',
        'elevenlabs.base_url',
        'elevenlabs.model_id',
        'azure.region',
        'azure.endpoint',
        'azure.model',
        'whisper.base_url',
        'whisper.chunk_seconds',
        'whisper.vad',
        'app.pins',
        'budget.monthly_minutes',
        'budget.monthly_usd',
        'budget.warn_percent',
        'budget.prices',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{format: string, version: int, settings: array<string, mixed>} */
    public function export(): array
    {
        $all = $this->settings->all();
        $portable = [];
        foreach (self::PORTABLE as $key) {
            $portable[$key] = $all[$key] ?? null;
        }

        return ['format' => self::FORMAT, 'version' => self::VERSION, 'settings' => $portable];
    }

    /**
     * The portable settings found in [$json].
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException with a message that is safe to show
     */
    public function read(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('That file is too large to be a settings file.');
        }
        $doc = json_decode($json, true);
        if (! is_array($doc) || ($doc['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('That is not an EchoLedger console settings file.');
        }
        $settings = $doc['settings'] ?? null;
        if (! is_array($settings)) {
            throw new InvalidArgumentException('The file has no settings.');
        }

        return array_intersect_key($settings, array_flip(self::PORTABLE));
    }
}
