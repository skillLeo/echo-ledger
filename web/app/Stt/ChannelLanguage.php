<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * The language of one side of a recording on Auto, decided over the whole side instead of chunk by chunk (since v1.3,
 * pure, unit-tested). Whisper detects a language per request from its first 30 s, so a mixed Urdu/English call comes
 * back as some chunks "en" (the Urdu translated or lost) and some "ur".
 *
 * - Meaningful Urdu anywhere on the side (a chunk heard as Urdu with a few words, Urdu letters, or Roman Urdu words such
 *   as "kya", "nahi", "theek") makes the side Urdu: Urdu always includes English words. Arabic works the same way.
 * - English is kept only where it is confidently pure: a side with no Urdu or Arabic at all, or one chunk of at least
 *   [MIN_ENGLISH_SECONDS] heard as English with no Urdu or Arabic in it. Nothing is ever forced to English.
 */
final class ChannelLanguage
{
    /** A chunk heard as English is kept as English on an Urdu side only from this long (a long pure English stretch). */
    public const MIN_ENGLISH_SECONDS = 60.0;

    /** Fewer words than this in a chunk heard as Urdu or Arabic is not enough to call the side so (Whisper guesses). */
    private const MIN_WORDS = 3;

    /**
     * Roman Urdu words that are not English words: how Whisper writes Urdu when it believes it hears English. Kept to
     * words with no English meaning ("to", "me", "main" and "par" are left out).
     */
    private const ROMAN_URDU = [
        'hai', 'hain', 'nahi', 'nahin', 'nai', 'kya', 'kyun', 'kyon', 'kaise', 'kaisa', 'kaisi', 'theek', 'thik', 'acha',
        'achha', 'accha', 'haan', 'han', 'ji', 'jee', 'yaar', 'bhai', 'abhi', 'aap', 'apna', 'apni', 'mujhe', 'mujhay',
        'tumhe', 'tum', 'hum', 'raha', 'rahi', 'rahe', 'karo', 'karna', 'karte', 'kar', 'gaya', 'gayi', 'wala', 'wali',
        'bohat', 'bahut', 'kuch', 'matlab', 'lekin', 'phir', 'tha', 'thi', 'hoon', 'hun', 'mera', 'meri', 'tera', 'teri',
        'kab', 'kahan', 'yahan', 'wahan', 'bilkul', 'shukriya', 'inshallah', 'chalo', 'dekho', 'suno', 'batao', 'bata',
    ];

    /** Distinct Roman Urdu words a chunk needs to count as Urdu. */
    private const MIN_ROMAN_URDU = 2;

    /**
     * The side's language: 'ur', 'ar', the one language every chunk agreed on, or null (nothing heard).
     *
     * @param  list<array{language: ?string, text: string, seconds: float}>  $chunks  what each chunk was heard as on Auto
     */
    public static function decide(array $chunks): ?string
    {
        $weight = ['ur' => 0, 'ar' => 0];
        $heard = [];
        foreach ($chunks as $chunk) {
            $evidence = self::evidence($chunk);
            if ($evidence !== null) {
                $weight[$evidence] += max(1, self::words($chunk['text']));
            }
            if ($chunk['language'] !== null && trim($chunk['text']) !== '') {
                $heard[$chunk['language']] = ($heard[$chunk['language']] ?? 0) + self::words($chunk['text']);
            }
        }
        if ($weight['ur'] > 0 || $weight['ar'] > 0) {
            return $weight['ar'] > $weight['ur'] ? 'ar' : 'ur';
        }
        arsort($heard);

        return array_key_first($heard);
    }

    /**
     * The language [$chunk] must be run again in on a side that is [$side], or null when it may stay as heard: on an
     * Urdu or Arabic side every chunk heard otherwise, except a long, confidently pure English one.
     *
     * @param  array{language: ?string, text: string, seconds: float}  $chunk
     */
    public static function rerunAs(?string $side, array $chunk): ?string
    {
        if (! in_array($side, ['ur', 'ar'], true) || $chunk['language'] === $side || trim($chunk['text']) === '') {
            return null;
        }
        if ($chunk['language'] === 'en' && $chunk['seconds'] >= self::MIN_ENGLISH_SECONDS && self::evidence($chunk) === null) {
            return null;
        }

        return $side;
    }

    /**
     * What a chunk shows of Urdu or Arabic: 'ur', 'ar' or null.
     *
     * @param  array{language: ?string, text: string, seconds: float}  $chunk
     */
    public static function evidence(array $chunk): ?string
    {
        $text = $chunk['text'];
        $counts = ScriptCheck::counts($text);
        $letters = max(1, array_sum($counts));
        $words = self::words($text);
        // Whisper names Urdu speech Arabic (or Persian) at times: letters only Urdu uses settle it.
        $arabic = $chunk['language'] === 'ar' && ! self::hasUrduLetters($text) ? 'ar' : 'ur';
        // Heard as Urdu or Arabic with a few words: Whisper's language, trusted.
        if (in_array($chunk['language'], ['ur', 'ar'], true) && $words >= self::MIN_WORDS) {
            return $chunk['language'] === 'ur' ? 'ur' : $arabic;
        }
        // Arabic-script letters (or Hindi letters, which is Urdu written wrong) in a fair share of the text.
        if (($counts['arabic'] + $counts['devanagari']) / $letters >= 0.2 && $words >= self::MIN_WORDS) {
            return $counts['arabic'] > 0 ? $arabic : 'ur';
        }

        return self::romanUrduWords($text) >= self::MIN_ROMAN_URDU ? 'ur' : null;
    }

    /** Two or more letters Urdu writes and Arabic does not (ٹ ڈ ڑ ں ے ہ ھ). */
    public static function hasUrduLetters(string $text): bool
    {
        return preg_match_all('/[\x{0679}\x{0688}\x{0691}\x{06BA}\x{06D2}\x{06C1}\x{06BE}]/u', $text) >= 2;
    }

    /**
     * Whether a finished transcript has Urdu (or Arabic) in it, so "English only" would make the engine translate it:
     * its language, or its text (Urdu letters, Roman Urdu words).
     */
    public static function hasUrdu(?string $language, string $text): bool
    {
        if (in_array(LanguageChoice::fromDetected($language), ['ur', 'ar'], true)) {
            return true;
        }

        return self::evidence(['language' => null, 'text' => mb_substr($text, 0, 4000), 'seconds' => 0.0]) !== null;
    }

    /** Distinct Roman Urdu words in [$text]. */
    public static function romanUrduWords(string $text): int
    {
        $tokens = preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count(array_intersect(array_unique($tokens), self::ROMAN_URDU));
    }

    private static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
