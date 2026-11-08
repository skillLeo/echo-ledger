<?php

declare(strict_types=1);

namespace App\Stt;

use App\Services\SettingsRepository;

/**
 * The Arabic dialect hint (since v1.5, Phase 1C): Settings → Recording → "Arabic dialect" (none by default). Engines
 * that take context get it: Whisper on Groq as a style sentence in that dialect, Soniox and Gemini as a line of text.
 * ElevenLabs Scribe takes no context (key terms only), so it gets nothing.
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

    /** @var array<string, string> a sentence in the dialect, with English words as people say them in calls */
    private const STYLE = [
        'gulf' => 'هلا، شلونك؟ اليوم بنتكلم عن ال invoice وال payment وال deadline. زين، برسل لك email.',
        'egyptian' => 'أهلاً، إزيك؟ النهارده هنتكلم عن ال invoice وال payment وال deadline. ماشي، هبعتلك email.',
        'levantine' => 'مرحبا، كيفك؟ اليوم رح نحكي عن ال invoice وال payment وال deadline. تمام، رح ابعتلك email.',
        'msa' => 'مرحبا، في اجتماع اليوم سنتحدث عن invoice و payment و deadline. حسناً، سأرسل email.',
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

    /** Whisper's style sentence for an Arabic recording in the chosen dialect; null when no dialect is set. */
    public static function whisperStyle(?string $language, ?SettingsRepository $settings = null): ?string
    {
        if (LanguageChoice::normalize($language) !== 'ar') {
            return null;
        }
        $dialect = self::dialect($settings);

        return $dialect === null ? null : self::STYLE[$dialect];
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
