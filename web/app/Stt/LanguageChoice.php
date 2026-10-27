<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * The transcription language of a recording, client or the console default (since v1.1): `null` (automatic), one
 * ISO 639-1 code such as `en`, `ur` or `hi`, or two codes joined by `+` for a call that switches between languages
 * (`en+ur`, `en+hi`; English always first, so one choice has one spelling). Each engine gets what its documentation
 * describes (docs/PROVIDERS.md "Languages"): AssemblyAI takes one `language_code`, or two `language_codes` with
 * English for code switching; ElevenLabs Scribe takes one `language_code` (ISO 639-1 or 639-3), so a mixed call is
 * sent as its non-English language; Soniox takes `language_hints`.
 */
final class LanguageChoice
{
    /** The choices the apps and the console offer. */
    public const OFFERED = [
        '' => 'Automatic',
        'en' => 'English',
        'ur' => 'Urdu',
        'en+ur' => 'Urdu + English',
        'hi' => 'Hindi',
        'en+hi' => 'Hindi + English',
    ];

    /** Short labels for chips in the apps, the console list and exports. */
    public const CHIPS = [
        '' => 'Auto',
        'en' => 'EN',
        'ur' => 'UR',
        'en+ur' => 'UR+EN',
        'hi' => 'HI',
        'en+hi' => 'HI+EN',
    ];

    /** A stored or requested value as kept: lower-case codes, `auto` and blanks become null, junk becomes null. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'auto') {
            return null;
        }
        $codes = array_values(array_unique(array_filter(array_map('trim', explode('+', $value)), fn (string $c): bool => $c !== '')));
        foreach ($codes as $code) {
            if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code) !== 1) {
                return null;
            }
        }
        $codes = array_slice($codes, 0, 2);
        // English first: "ur+en" and "en+ur" are the same choice.
        usort($codes, fn (string $a, string $b): int => ($a === 'en' ? 0 : 1) <=> ($b === 'en' ? 0 : 1));

        return $codes === [] ? null : implode('+', $codes);
    }

    /** @return list<string> the codes of a choice (empty for automatic) */
    public static function codes(mixed $value): array
    {
        $normal = self::normalize($value);

        return $normal === null ? [] : explode('+', $normal);
    }

    /** The one language to send to an engine that takes a single code; null for automatic or mixed speech. */
    public static function single(mixed $value): ?string
    {
        $codes = self::codes($value);

        return count($codes) === 1 ? $codes[0] : null;
    }

    /**
     * The language an engine with a single `language_code` should be told: the code itself, or for a mixed call the
     * language that is not English (Urdu with English words is Urdu speech). Null only for automatic.
     */
    public static function primary(mixed $value): ?string
    {
        $codes = self::codes($value);
        if ($codes === []) {
            return null;
        }
        foreach ($codes as $code) {
            if ($code !== 'en') {
                return $code;
            }
        }

        return $codes[0];
    }

    /** True for two languages AssemblyAI's code switching accepts: exactly two, one of them English. */
    public static function assemblyAiCodeSwitch(mixed $value): bool
    {
        $codes = array_map(fn (string $c): string => explode('-', $c)[0], self::codes($value));

        return count($codes) === 2 && in_array('en', $codes, true);
    }

    /** Urdu is spoken in the recording, so the text should be in Urdu (Arabic) script. */
    public static function expectsUrduScript(mixed $value): bool
    {
        return in_array('ur', self::codes($value), true);
    }

    /** Hindi is spoken in the recording, so the text should be in Devanagari. */
    public static function expectsDevanagari(mixed $value): bool
    {
        return in_array('hi', self::codes($value), true);
    }

    public static function label(mixed $value): string
    {
        $normal = self::normalize($value);
        if ($normal === null) {
            return self::OFFERED[''];
        }

        return self::OFFERED[$normal] ?? strtoupper(str_replace('+', ' + ', $normal));
    }

    public static function chip(mixed $value): string
    {
        $normal = self::normalize($value);

        return self::CHIPS[$normal ?? ''] ?? strtoupper((string) $normal);
    }
}
