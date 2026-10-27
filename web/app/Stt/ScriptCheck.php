<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * Is a transcript written in the script its language uses? Spoken Urdu and Hindi sound alike; an engine that guesses
 * Hindi writes Urdu speech in Devanagari (or romanises it). This counts letters per script and names the problem.
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

    /**
     * Null when the script fits; otherwise the script that came back instead (`devanagari`, `latin` or `arabic`).
     * Urdu: mostly Devanagari is wrong; for Urdu alone (not mixed with English), mostly Latin letters is wrong too.
     * Hindi: mostly Urdu (Arabic) script is wrong.
     */
    public static function wrongScript(?string $language, string $text): ?string
    {
        $c = self::counts($text);
        $letters = $c['arabic'] + $c['devanagari'] + $c['latin'];
        if ($letters < self::MIN_LETTERS) {
            return null;
        }
        if (LanguageChoice::expectsUrduScript($language)) {
            if ($c['devanagari'] > $c['arabic'] && $c['devanagari'] / $letters >= 0.3) {
                return 'devanagari';
            }
            $mixed = in_array('en', LanguageChoice::codes($language), true);
            if (! $mixed && $c['arabic'] / $letters < 0.2 && $c['latin'] / $letters >= 0.6) {
                return 'latin';
            }
        }
        if (LanguageChoice::expectsDevanagari($language) && $c['arabic'] > $c['devanagari'] && $c['arabic'] / $letters >= 0.3) {
            return 'arabic';
        }

        return null;
    }

    public static function label(string $found): string
    {
        return match ($found) {
            'devanagari' => 'Hindi (Devanagari) script',
            'latin' => 'Latin letters',
            'arabic' => 'Urdu script',
            default => $found,
        };
    }
}
