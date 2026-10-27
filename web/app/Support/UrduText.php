<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Folds the spelling variants of Urdu (and Arabic-script loanwords) to one form for search, so a word is found however
 * the engine or the keyboard wrote it: Arabic Yeh / Alef Maksura → Farsi Yeh (ی), Arabic Kaf → Keheh (ک), Arabic Heh,
 * Teh Marbuta and Heh with Yeh above → Heh Goal (ہ), Alef with Hamza / Madda / Wasla → Alef (ا), Waw with Hamza → Waw,
 * Yeh with Hamza → Farsi Yeh, Yeh Barree with Hamza → Yeh Barree, Arabic-Indic and Extended Arabic-Indic digits →
 * 0-9; harakat, superscript Alef, tatweel and zero-width joiners are dropped. Latin text (English loanwords) and
 * Devanagari are left as they are, so both scripts stay searchable.
 */
final class UrduText
{
    private const MAP = [
        "\u{064A}" => "\u{06CC}", "\u{0649}" => "\u{06CC}", "\u{0626}" => "\u{06CC}",
        "\u{0643}" => "\u{06A9}",
        "\u{0647}" => "\u{06C1}", "\u{0629}" => "\u{06C1}", "\u{06C0}" => "\u{06C1}", "\u{06C3}" => "\u{06C1}",
        "\u{0623}" => "\u{0627}", "\u{0625}" => "\u{0627}", "\u{0622}" => "\u{0627}", "\u{0671}" => "\u{0627}",
        "\u{0624}" => "\u{0648}",
        "\u{06D3}" => "\u{06D2}",
    ];

    /** Characters dropped: harakat U+064B-U+065F, superscript Alef U+0670, tatweel U+0640, ZWNJ / ZWJ / LRM / RLM. */
    private const DROP = '/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{200C}\x{200D}\x{200E}\x{200F}]/u';

    public static function fold(string $text): string
    {
        return self::foldWithMap($text)[0];
    }

    /**
     * The folded text and, for each of its characters, the index of the character it came from, so highlight ranges
     * found in the folded text can be drawn on the original.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function foldWithMap(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $map = [];
        foreach ($chars as $i => $ch) {
            if (preg_match(self::DROP, $ch) === 1) {
                continue;
            }
            $cp = mb_ord($ch);
            if ($cp >= 0x0660 && $cp <= 0x0669) {
                $ch = (string) ($cp - 0x0660);
            } elseif ($cp >= 0x06F0 && $cp <= 0x06F9) {
                $ch = (string) ($cp - 0x06F0);
            } else {
                $ch = self::MAP[$ch] ?? $ch;
            }
            // Lower-casing can turn one character into two (İ → i̇): every output character still points at its source.
            foreach (preg_split('//u', mb_strtolower($ch), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $out .= $piece;
                $map[] = $i;
            }
        }

        return [$out, $map];
    }
}
