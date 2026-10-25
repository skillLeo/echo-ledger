<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Side-by-side comparison of two transcripts of the same recording (pure). Both are cut into time windows (30 s by
 * default) so long calls stay cheap, and inside each window the words are compared (longest common subsequence,
 * ignoring case and punctuation). Changed words are marked on both sides.
 */
final class TranscriptDiff
{
    public const WINDOW_MS = 30_000;

    /**
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return array{rows: list<array{start_ms: int, a: list<array{0: string, 1: bool}>, b: list<array{0: string, 1: bool}>}>, words_a: int, words_b: int, changed: int}
     */
    public static function compare(array $a, array $b, int $windowMs = self::WINDOW_MS): array
    {
        $windowsA = self::windows($a, $windowMs);
        $windowsB = self::windows($b, $windowMs);
        $keys = array_unique([...array_keys($windowsA), ...array_keys($windowsB)]);
        sort($keys);
        $rows = [];
        $wordsA = 0;
        $wordsB = 0;
        $changed = 0;
        foreach ($keys as $key) {
            [$left, $right] = self::words($windowsA[$key] ?? '', $windowsB[$key] ?? '');
            $wordsA += count($left);
            $wordsB += count($right);
            $changed += count(array_filter($left, fn (array $w): bool => $w[1])) + count(array_filter($right, fn (array $w): bool => $w[1]));
            $rows[] = ['start_ms' => $key * $windowMs, 'a' => $left, 'b' => $right];
        }

        return ['rows' => $rows, 'words_a' => $wordsA, 'words_b' => $wordsB, 'changed' => $changed];
    }

    /**
     * Word-level comparison of two texts: each side's words with `true` where the word is not in the other side's
     * common sequence.
     *
     * @return array{0: list<array{0: string, 1: bool}>, 1: list<array{0: string, 1: bool}>}
     */
    public static function words(string $a, string $b): array
    {
        $left = self::tokens($a);
        $right = self::tokens($b);
        $ka = array_map([self::class, 'key'], $left);
        $kb = array_map([self::class, 'key'], $right);
        $n = count($ka);
        $m = count($kb);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $ka[$i] === $kb[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $markA = array_fill(0, $n, true);
        $markB = array_fill(0, $m, true);
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($ka[$i] === $kb[$j]) {
                $markA[$i] = false;
                $markB[$j] = false;
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return [
            array_map(fn (string $w, bool $changed): array => [$w, $changed], $left, $markA),
            array_map(fn (string $w, bool $changed): array => [$w, $changed], $right, $markB),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return array<int, string> window index → text
     */
    private static function windows(array $segments, int $windowMs): array
    {
        $out = [];
        foreach ($segments as $segment) {
            $key = intdiv(max(0, (int) ($segment['start_ms'] ?? 0)), max(1, $windowMs));
            $out[$key] = trim(($out[$key] ?? '').' '.(string) ($segment['text'] ?? ''));
        }

        return $out;
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($text)) ?: [], fn (string $w): bool => $w !== ''));
    }

    private static function key(string $word): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($word));
    }
}
