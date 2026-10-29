<?php

declare(strict_types=1);

namespace App\Stt;

use App\Services\SettingsRepository;

/**
 * The transcription language of a recording, client or the console default: `null` (Auto) or one code (since v1.2).
 *
 * - Auto picks only among English, Urdu and Arabic (plus the "More languages" the console ticks for Auto); an engine
 *   that detects anything else is run again in the recording's default language.
 * - Urdu always allows English words inside (Pakistani mixed speech), Arabic too: engines are told both languages may
 *   be heard, and the text keeps each word in its own script.
 * - Hindi is not offered: spoken Urdu and Hindi sound alike, and an engine that picks Hindi writes Urdu speech in
 *   Devanagari. Stored `hi`, `en+ur` and `en+hi` (v1.1) read as `ur`; a detected `hi` is Urdu.
 */
final class LanguageChoice
{
    /** The choices every menu shows first, labelled for the menu (since v1.3: what each choice does). */
    public const MAIN = [
        '' => 'Auto (English / Urdu / Arabic)',
        'en' => 'English only',
        'ur' => 'Urdu (with English words)',
        'ar' => 'Arabic (with English words)',
    ];

    /** The main choices' names inside a sentence ("Re-transcribing in Urdu"). */
    private const NAMES = [
        '' => 'Auto',
        'en' => 'English',
        'ur' => 'Urdu',
        'ar' => 'Arabic',
    ];

    /** "More languages ▸": add a code here (and to the apps' lists) to offer another one. */
    public const MORE = [
        'fr' => 'French',
        'de' => 'German',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'tr' => 'Turkish',
        'ru' => 'Russian',
    ];

    /** Every choice with its label ('' is Auto). */
    public const OFFERED = self::MAIN + self::MORE;

    /** Short labels for chips in the apps, the console list and exports. */
    public const CHIPS = [
        '' => 'Auto',
        'en' => 'EN',
        'ur' => 'UR',
        'ar' => 'AR',
        'fr' => 'FR',
        'de' => 'DE',
        'es' => 'ES',
        'it' => 'IT',
        'pt' => 'PT',
        'nl' => 'NL',
        'tr' => 'TR',
        'ru' => 'RU',
    ];

    /** What Auto may pick when the console has not ticked any of the "More languages" for it. */
    public const AUTO_DEFAULT = ['en', 'ur', 'ar'];

    /** Choices whose speech mixes in English words: the engine is told English may be heard too. */
    public const WITH_ENGLISH = ['ur', 'ar'];

    /** Old and alternative spellings of a choice: v1.1 codes, ISO 639-2/3 codes and language names engines return. */
    private const ALIASES = [
        'hi' => 'ur', 'hin' => 'ur', 'hindi' => 'ur', 'urd' => 'ur', 'urdu' => 'ur',
        'eng' => 'en', 'english' => 'en',
        'ara' => 'ar', 'arb' => 'ar', 'arabic' => 'ar',
        'fra' => 'fr', 'fre' => 'fr', 'french' => 'fr',
        'deu' => 'de', 'ger' => 'de', 'german' => 'de',
        'spa' => 'es', 'spanish' => 'es',
        'ita' => 'it', 'italian' => 'it',
        'por' => 'pt', 'portuguese' => 'pt',
        'nld' => 'nl', 'dut' => 'nl', 'dutch' => 'nl',
        'tur' => 'tr', 'turkish' => 'tr',
        'rus' => 'ru', 'russian' => 'ru',
    ];

    /**
     * A stored or requested value as kept: one offered code, or null for Auto (blank, `auto`, unknown). Mixed v1.1
     * values keep their non-English language ("en+ur" is Urdu), Hindi reads as Urdu, regions are dropped ("ar-EG").
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'auto') {
            return null;
        }
        $codes = array_values(array_filter(array_map(fn (string $c): ?string => self::code($c), explode('+', $value))));
        if ($codes === []) {
            return null;
        }
        foreach ($codes as $code) {
            if ($code !== 'en') {
                return $code;
            }
        }

        return 'en';
    }

    /** One code or name ("ur", "urd", "Urdu", "ar-EG") as an offered code, or null. */
    private static function code(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        $base = preg_replace('/[-_].*$/', '', $raw) ?? $raw;
        $code = self::ALIASES[$raw] ?? self::ALIASES[$base] ?? $base;

        return $code !== '' && array_key_exists($code, self::OFFERED) ? $code : null;
    }

    /**
     * The language an engine reported (ISO code or name) as one of ours: `hi` is Urdu. Null when it is not a
     * language we offer at all (the engine is then run again in the recording's language).
     */
    public static function fromDetected(mixed $detected): ?string
    {
        return is_string($detected) && trim($detected) !== '' ? self::code($detected) : null;
    }

    /**
     * The languages an engine may hear for a choice: Urdu and Arabic come with English, the others alone; Auto has
     * no fixed list (see [autoLanguages]).
     *
     * @return list<string>
     */
    public static function codes(mixed $value): array
    {
        $code = self::normalize($value);
        if ($code === null) {
            return [];
        }

        return in_array($code, self::WITH_ENGLISH, true) ? [$code, 'en'] : [$code];
    }

    /** The one language of a choice (every choice is one language since v1.2); null for Auto. */
    public static function single(mixed $value): ?string
    {
        return self::normalize($value);
    }

    /** The language an engine that takes a single `language_code` is told; null for Auto. */
    public static function primary(mixed $value): ?string
    {
        return self::normalize($value);
    }

    /** Urdu is spoken in the recording, so the text should be in Urdu (Arabic) script. */
    public static function expectsUrduScript(mixed $value): bool
    {
        return self::normalize($value) === 'ur';
    }

    /**
     * The languages Auto may pick: English, Urdu and Arabic, plus the "More languages" ticked for Auto in the console
     * (`languages.auto_more`).
     *
     * @return list<string>
     */
    public static function autoLanguages(?SettingsRepository $settings = null): array
    {
        $more = ($settings ?? app(SettingsRepository::class))->get('languages.auto_more');
        $extra = is_array($more) ? array_values(array_filter(array_map(fn ($c): ?string => self::normalize($c), $more))) : [];

        return array_values(array_unique([...self::AUTO_DEFAULT, ...array_filter($extra, fn (string $c): bool => array_key_exists($c, self::MORE))]));
    }

    /** May a recording with [$choice] (null = Auto) come back in the detected language [$detected]? */
    public static function allows(?string $choice, ?string $detected, ?SettingsRepository $settings = null): bool
    {
        if ($detected === null) {
            return false;
        }
        $choice = self::normalize($choice);
        if ($choice === null) {
            return in_array($detected, self::autoLanguages($settings), true);
        }

        return $detected === $choice || (in_array($choice, self::WITH_ENGLISH, true) && $detected === 'en');
    }

    /**
     * The language a recording is forced into when Auto (or an engine) picked one it may not use: the console's
     * fallback (`languages.auto_fallback`, Urdu unless changed).
     */
    public static function fallback(?SettingsRepository $settings = null): string
    {
        return self::normalize(($settings ?? app(SettingsRepository::class))->get('languages.auto_fallback')) ?? 'ur';
    }

    /** The language's name for a sentence or a chip line ("Urdu"); menus use [OFFERED]. */
    public static function label(mixed $value): string
    {
        $code = self::normalize($value) ?? '';

        return self::NAMES[$code] ?? self::OFFERED[$code];
    }

    public static function chip(mixed $value): string
    {
        return self::CHIPS[self::normalize($value) ?? ''];
    }

    /**
     * The choices for a select in the console: Auto and the main languages, then "More languages".
     *
     * @return array<string, string|array<string, string>>
     */
    public static function selectOptions(): array
    {
        return [...self::MAIN, 'More languages' => self::MORE];
    }
}
