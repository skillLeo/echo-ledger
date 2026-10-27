<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * Is a transcript written in the script its language uses? Spoken Urdu and Hindi sound alike; an engine that guesses
 * Hindi writes Urdu speech in Devanagari (or romanises it). Hindi is not offered (since v1.2), so Devanagari is wrong
 * for every recording. This counts letters per script and names the problem.
 */
final class ScriptCheck
{
    /** At least this many letters before judging: a few words prove nothing. */
    public const MIN_LETTERS = 40;

    /** @return array{arabic: int, devanagari: int, latin: int} letters per script */
    public static function counts(string $text): array
    {
        return [
            // Arabic, Arabic Supplement, Arabic Presentation Forms A and B (Urdu letters live in these blocks).
            'arabic' => (int) preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text),
            'devanagari' => (int) preg_match_all('/[\x{0900}-\x{097F}]/u', $text),
            'latin' => (int) preg_match_all('/[A-Za-z]/', $text),
        ];
    }

    /** Any Devanagari letter at all (a line of live captions or one segment). */
    public static function hasDevanagari(string $text): bool
    {
        return preg_match('/[\x{0900}-\x{097F}]/u', $text) === 1;
    }

    /**
     * Null when the script fits; otherwise the script that came back instead (`devanagari` or `latin`).
     * Any recording: Devanagari is wrong once it is a fifth of the letters. Urdu or Arabic: text almost entirely in
     * Latin letters (romanised, "Roman Urdu") is wrong too; English words inside Urdu are normal and stay.
     */
    public static function wrongScript(?string $language, string $text): ?string
    {
        $c = self::counts($text);
        $letters = $c['arabic'] + $c['devanagari'] + $c['latin'];
        if ($letters < self::MIN_LETTERS) {
            return null;
        }
        if ($c['devanagari'] / $letters >= 0.2) {
            return 'devanagari';
        }
        $code = LanguageChoice::normalize($language);
        if (in_array($code, ['ur', 'ar'], true) && $c['latin'] / $letters >= 0.85 && $c['arabic'] / $letters < 0.05) {
            return 'latin';
        }

        return null;
    }

    public static function label(string $found): string
    {
        return match ($found) {
            'devanagari' => 'Hindi (Devanagari) script',
            'latin' => 'Latin letters',
            default => $found,
        };
    }
}
