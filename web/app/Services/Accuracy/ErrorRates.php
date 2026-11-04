<?php

declare(strict_types=1);

namespace App\Services\Accuracy;

use App\Support\UrduText;

/**
 * Word and character error rates of a transcript against a reference (Accuracy Lab, since v1.2), after the same
 * normalising for both: lower case, Urdu spelling variants folded, punctuation removed (both are the spoken words
 * only, line by line, without speaker names). Long texts are scored on their first [MAX_WORDS] words (and the
 * characters of those words), which keeps a score fast enough to run on the shared host. Pure; unit-tested.
 */
final class ErrorRates
{
    public const MAX_WORDS = 4000;

    public const MAX_CHARS = 8000;

    /**
     * The normalised words; [$roman] also folds Roman Urdu spelling (see foldRoman).
     *
     * @return list<string>
     */
    public static function words(string $text, bool $roman = false): array
    {
        $text = mb_strtolower(UrduText::fold($text));
        $text = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);
        $words = array_filter(preg_split('/\s+/u', trim($text)) ?: [], fn (string $w): bool => $w !== '');
        if ($roman) {
            $words = array_map(self::foldRoman(...), $words);
        }

        return array_slice($words, 0, self::MAX_WORDS);
    }

    /**
     * Since v1.5 (the Roman Urdu view is scored on its own): Roman Urdu has no fixed spelling ("theek" / "thik",
     * "zaroor" / "zarur", "haan" / "han"), so long vowels written twice and doubled letters are folded and a score
     * counts wrong words, not spelling habits.
     */
    public static function foldRoman(string $word): string
    {
        return (string) preg_replace('/(\p{L})\1+/u', '$1', str_replace(['ee', 'oo'], ['i', 'u'], $word));
    }

    /** Word error rate: (substitutions + deletions + insertions) / reference words; 0 for two empty texts. */
    public static function wer(string $reference, string $hypothesis, bool $roman = false): float
    {
        $ref = self::words($reference, $roman);
        $hyp = array_slice(self::words($hypothesis, $roman), 0, max(count($ref) * 2, 1));

        return $ref === [] ? ($hyp === [] ? 0.0 : 1.0) : self::distance($ref, $hyp) / count($ref);
    }

    /** Character error rate over the normalised words joined by single spaces. */
    public static function cer(string $reference, string $hypothesis, bool $roman = false): float
    {
        $ref = mb_str_split(mb_substr(implode(' ', self::words($reference, $roman)), 0, self::MAX_CHARS));
        $hyp = mb_str_split(mb_substr(implode(' ', self::words($hypothesis, $roman)), 0, max(count($ref) * 2, 1)));

        return $ref === [] ? ($hyp === [] ? 0.0 : 1.0) : self::distance($ref, $hyp) / count($ref);
    }

    /**
     * Levenshtein distance over two lists, one row at a time.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function distance(array $a, array $b): int
    {
        $n = count($b);
        $previous = range(0, $n);
        foreach ($a as $i => $x) {
            $current = [$i + 1];
            for ($j = 0; $j < $n; $j++) {
                $cost = $x === $b[$j] ? 0 : 1;
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + $cost);
            }
            $previous = $current;
        }

        return $previous[$n];
    }
}
