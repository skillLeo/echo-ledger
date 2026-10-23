<?php

declare(strict_types=1);

namespace App\Stt;

use App\Stt\Data\ProviderCapabilities;

/**
 * Keyterm hygiene shared by every provider (limits from docs/research/stt-*.md):
 * trim, collapse whitespace, strip characters ElevenLabs rejects, case-insensitive dedupe, then per-provider caps.
 */
final class KeytermPolicy
{
    /** Admin-side hard limits (DB column is 100 chars; every provider accepts ≤ 50 in batch). */
    public const MAX_CHARS = 50;

    public const MAX_WORDS = 5;

    /**
     * @param  iterable<mixed>  $terms
     * @return list<string>
     */
    public static function sanitize(iterable $terms): array
    {
        $seen = [];
        $out = [];
        foreach ($terms as $term) {
            $clean = trim((string) preg_replace('/\s+/u', ' ', str_replace(['<', '>', '{', '}', '[', ']', '\\'], '', (string) $term)));
            if ($clean === '') {
                continue;
            }
            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    public static function forProvider(array $terms, ProviderCapabilities $caps, bool $realtime): array
    {
        $maxCount = $realtime ? $caps->maxRealtimeKeyterms : $caps->maxKeyterms;
        $maxChars = $realtime ? $caps->maxRealtimeKeytermChars : $caps->maxKeytermChars;

        $filtered = array_filter(self::sanitize($terms), function (string $t) use ($maxChars, $caps): bool {
            return mb_strlen($t) <= $maxChars && count(explode(' ', $t)) <= $caps->maxKeytermWords;
        });

        return array_slice(array_values($filtered), 0, max(0, $maxCount));
    }

    /** Validation message for one admin-entered term, or null when valid. */
    public static function validate(string $term): ?string
    {
        $clean = self::sanitize([$term])[0] ?? '';
        if ($clean === '') {
            return 'Keyterm cannot be empty.';
        }
        if (mb_strlen($clean) > self::MAX_CHARS) {
            return 'Keep keyterms to '.self::MAX_CHARS.' characters or fewer.';
        }
        if (count(explode(' ', $clean)) > self::MAX_WORDS) {
            return 'Keep keyterms to '.self::MAX_WORDS.' words or fewer.';
        }

        return null;
    }
}
