<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * The transcription language of a recording, client or the console default (since v1.1): `null` (automatic), one
 * ISO 639-1 code such as `en`, `ur` or `hi`, or two codes joined by `+` for a call that switches between languages
 * (`en+ur`). Providers get what they document: AssemblyAI's Universal-2 takes two codes, one of them English
 * (docs/research/stt-assemblyai.md); every other engine detects mixed speech by itself.
 */
final class LanguageChoice
{
    /** The choices the apps and the console offer. */
    public const OFFERED = [
        '' => 'Automatic',
        'en' => 'English',
        'ur' => 'Urdu',
        'hi' => 'Hindi',
        'en+ur' => 'English + Urdu',
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

        return $codes === [] ? null : implode('+', array_slice($codes, 0, 2));
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

    /** True for two languages AssemblyAI's code switching accepts: exactly two, one of them English. */
    public static function assemblyAiCodeSwitch(mixed $value): bool
    {
        $codes = array_map(fn (string $c): string => explode('-', $c)[0], self::codes($value));

        return count($codes) === 2 && in_array('en', $codes, true);
    }

    public static function label(mixed $value): string
    {
        $normal = self::normalize($value);
        if ($normal === null) {
            return self::OFFERED[''];
        }

        return self::OFFERED[$normal] ?? strtoupper(str_replace('+', ' + ', $normal));
    }
}
