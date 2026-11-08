<?php

declare(strict_types=1);

namespace App\Stt;

use App\Services\SettingsRepository;

/**
 * The Arabic dialect hint (since v1.5, Phase 1C): Settings → Recording → "Arabic dialect" (none by default). Engines
 * that read context as instructions get it as a line of text: Soniox and Gemini. Whisper does not: it writes a sample
 * sentence in its prompt back instead of the audio (WhisperChecks), so its prompt holds names only. ElevenLabs Scribe
 * takes no context (key terms only).
 */
final class DialectHint
{
    public const SETTING = 'arabic.dialect';

    /** @var array<string, string> value => label */
    public const OPTIONS = [
        'gulf' => 'Gulf / Saudi',
        'egyptian' => 'Egyptian',
        'levantine' => 'Levantine',
        'msa' => 'Standard (MSA)',
    ];

    /** @var array<string, string> */
    private const DESCRIBE = [
        'gulf' => 'Gulf (Saudi) Arabic',
        'egyptian' => 'Egyptian Arabic',
        'levantine' => 'Levantine Arabic',
        'msa' => 'Modern Standard Arabic',
    ];

    public static function dialect(?SettingsRepository $settings = null): ?string
    {
        $value = ($settings ?? app(SettingsRepository::class))->get(self::SETTING);

        return is_string($value) && array_key_exists($value, self::OPTIONS) ? $value : null;
    }

    /** One line of context for engines that read text ("Gulf (Saudi) Arabic speech, with English words."). */
    public static function text(?string $language, ?SettingsRepository $settings = null): ?string
    {
        if (LanguageChoice::normalize($language) !== 'ar') {
            return null;
        }
        $dialect = self::dialect($settings);

        return $dialect === null ? null : self::DESCRIBE[$dialect].' speech, with English words.';
    }
}
