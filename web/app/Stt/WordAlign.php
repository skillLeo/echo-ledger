<?php

declare(strict_types=1);

namespace App\Stt;

use App\Support\UrduText;

/**
 * Lines up the words two engines heard (since v1.5, Phase 1C): a word-level edit-distance alignment, compared after
 * folding case, punctuation, diacritics and Urdu/Arabic letter forms, so only real differences count.
 */
final class WordAlign
{
    /** The comparable form of a word: "Ahmed," and "ahmed" match; أفحم and أفهم do not. */
    public static function key(string $word): string
    {
        return trim((string) preg_replace('/[\p{P}\p{S}\s]+/u', '', mb_strtolower(UrduText::fold($word))));
    }

    /** @return list<string> the words of a text, punctuation kept on them, empty ones dropped */
    public static function tokens(string $text): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: [], fn (string $w): bool => self::key($w) !== ''));
    }

    /**
     * The alignment of [$a] and [$b] as operations in order: `same` and `sub` (both indexes), `del` (a word only in
     * [$a]), `ins` (a word only in [$b]).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{op: string, a: int|null, b: int|null}>
     */
    public static function align(array $a, array $b): array
    {
        $x = array_map(self::key(...), $a);
        $y = array_map(self::key(...), $b);
        $n = count($x);
        $m = count($y);
        $d = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
            }
        }
        $ops = [];
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $d[$i][$j] === $d[$i - 1][$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1)) {
                $ops[] = ['op' => $x[$i - 1] === $y[$j - 1] ? 'same' : 'sub', 'a' => $i - 1, 'b' => $j - 1];
                $i--;
                $j--;
            } elseif ($i > 0 && $d[$i][$j] === $d[$i - 1][$j] + 1) {
                $ops[] = ['op' => 'del', 'a' => $i - 1, 'b' => null];
                $i--;
            } else {
                $ops[] = ['op' => 'ins', 'a' => null, 'b' => $j - 1];
                $j--;
            }
        }

        return array_reverse($ops);
    }

    /**
     * Share of [$a]'s and [$b]'s words that match (0-1).
     *
     * @param  list<array{op: string, a: int|null, b: int|null}>  $ops
     */
    public static function agreement(array $ops): float
    {
        $longest = max(count(array_filter($ops, fn (array $o): bool => $o['a'] !== null)), count(array_filter($ops, fn (array $o): bool => $o['b'] !== null)));

        return $longest === 0 ? 1.0 : count(array_filter($ops, fn (array $o): bool => $o['op'] === 'same')) / $longest;
    }
}
