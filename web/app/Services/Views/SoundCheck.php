<?php

declare(strict_types=1);

namespace App\Services\Views;

/**
 * Views only transliterate (since v1.5, Phase 1C): compares a line's original words with a transliteration's words sound
 * for sound. Each word becomes a consonant skeleton in Latin letters (Urdu letters and Latin letters alike: "ٹھیک" and
 * "theek" are both "tk"; vowels, h, w and y left out, doubled letters once), so spelling variants and vowels do not
 * matter but another word does ("تعل" is "tl", "dekh" is "dk"). The words are lined up allowing one word to become two
 * or three ("کیلئے" → "ke liye", "واٹس ایپ" → "WhatsApp"), number words to become digits ("سولہ" → "16", "ساڑھے تین
 * بجے" → "3:30") and a currency to move ("دو ہزار روپے" → "Rs 2,000"). Pure; unit-tested.
 */
final class SoundCheck
{
    private const MARKER = '#U';

    /** Urdu (and Arabic) letters and the Latin consonant each stands for; letters not listed are vowels or silent. */
    private const URDU = [
        'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ٹ' => 't', 'ط' => 't', 'ث' => 's', 'س' => 's', 'ص' => 's', 'ج' => 'j',
        'چ' => 'C', 'خ' => 'k', 'د' => 'd', 'ڈ' => 'd', 'ذ' => 'z', 'ز' => 'z', 'ض' => 'z', 'ظ' => 'z', 'ژ' => 'z',
        'ر' => 'r', 'ڑ' => 'r', 'ش' => 'S', 'غ' => 'g', 'ف' => 'f', 'ق' => 'k', 'ک' => 'k', 'ك' => 'k', 'گ' => 'g',
        'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ں' => 'n',
    ];

    /** How capitals read aloud sound, for "GB", "PDF", "HR" written in Urdu letters as "جی بی". */
    private const LETTER_NAMES = [
        'A' => '', 'B' => 'b', 'C' => 's', 'D' => 'd', 'E' => '', 'F' => 'f', 'G' => 'j', 'H' => 'C', 'I' => '', 'J' => 'j',
        'K' => 'k', 'L' => 'l', 'M' => 'm', 'N' => 'n', 'O' => '', 'P' => 'p', 'Q' => 'k', 'R' => 'r', 'S' => 's', 'T' => 't',
        'U' => '', 'V' => '', 'W' => 'dbl', 'X' => 'ks', 'Y' => '', 'Z' => 'zd',
    ];

    /** Pairs that sound alike enough to cost half a letter. */
    private const NEAR = ['sz', 'zs', 'Ss', 'sS', 'jz', 'zj', 'gj', 'jg', 'CS', 'SC'];

    /** Number words (Urdu letters, Roman Urdu, English) a view writes as digits. */
    private const NUMBER_WORDS = [
        'صفر', 'زیرو', 'ایک', 'دو', 'تین', 'چار', 'پانچ', 'چھ', 'چھے', 'سات', 'آٹھ', 'نو', 'دس', 'گیارہ', 'بارہ', 'تیرہ', 'چودہ',
        'پندرہ', 'سولہ', 'سترہ', 'اٹھارہ', 'انیس', 'بیس', 'اکیس', 'بائیس', 'تئیس', 'چوبیس', 'پچیس', 'چھبیس', 'ستائیس',
        'اٹھائیس', 'انتیس', 'تیس', 'اکتیس', 'بتیس', 'پینتیس', 'چالیس', 'پینتالیس', 'پچاس', 'پچپن', 'ساٹھ', 'پینسٹھ', 'ستر',
        'پچھتر', 'اسی', 'پچاسی', 'نوے', 'سو', 'ہزار', 'لاکھ', 'کروڑ', 'ارب', 'ڈیڑھ', 'ڈھائی', 'ساڑھے', 'سوا', 'پونے', 'آدھا',
        'آدھے', 'پہلا', 'پہلی', 'دوسرا', 'دوسری', 'تیسرا', 'تیسری',
        'zero', 'ek', 'do', 'teen', 'char', 'chaar', 'panch', 'paanch', 'chay', 'chhe', 'saat', 'aath', 'nau', 'das', 'gyarah',
        'barah', 'baarah', 'terah', 'chodah', 'chaudah', 'pandrah', 'solah', 'satrah', 'atharah', 'unnees', 'bees', 'pachees',
        'tees', 'battees', 'chalees', 'pachas', 'pachaas', 'saath', 'sattar', 'assi', 'nabbe', 'sau', 'hazar', 'hazaar',
        'lakh', 'crore', 'karor', 'arab', 'dedh', 'dhai', 'sadhe', 'saadhe', 'sawa', 'paune', 'adha', 'aadha',
        'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
        'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty', 'thirty', 'forty', 'fifty', 'sixty',
        'seventy', 'eighty', 'ninety', 'hundred', 'thousand', 'million', 'billion', 'half', 'quarter', 'first', 'second', 'third',
    ];

    /** Words that go with a number and may be folded into it ("ساڑھے تین بجے" → "3:30", "سولہ جی بی" → "16GB"). */
    private const NUMBER_COMPANIONS = [
        'بجے', 'بجکر', 'منٹ', 'سیکنڈ', 'گھنٹے', 'گھنٹہ', 'فیصد', 'پرسنٹ', 'جی', 'بی', 'ایم', 'کے', 'کلو', 'گرام', 'میٹر', 'فٹ',
        'انچ', 'baje', 'bajay', 'minute', 'minutes', 'second', 'seconds', 'percent', 'gb', 'mb', 'kb', 'tb', 'kg', 'km', 'kilo',
        'gram', 'meter', 'feet', 'inch', "o'clock", 'oclock', 'pm', 'am',
    ];

    /** A currency a view may move in front of the amount ("دو ہزار روپے" → "Rs 2,000"). */
    private const CURRENCY_WORDS = ['روپے', 'روپیہ', 'روپئے', 'روپیے', 'ڈالر', 'rupay', 'rupaye', 'rupees', 'rupee', 'dollar', 'dollars'];

    private const CURRENCY_SIGNS = ['rs', 'rs.', 'pkr', 'usd', '$', 'aed', 'sar', '£', '€'];

    /**
     * Whether every word of [$source] has its own sound in [$view] and the view adds none.
     */
    public static function matches(string $source, string $view): bool
    {
        return self::failing($source, $view) === [] && self::added($source, $view) === 0;
    }

    /**
     * The indexes of the original's words (as [words] gives them) whose sound is not in the view.
     *
     * @return list<int>
     */
    public static function failing(string $source, string $view): array
    {
        $failed = [];
        foreach (self::align(self::words($source), self::words($view)) as [$from, $to, $ok]) {
            if (! $ok) {
                array_push($failed, ...$from);
            }
        }

        return $failed;
    }

    /** How many of the view's words stand for no word of the original. */
    public static function added(string $source, string $view): int
    {
        $added = 0;
        foreach (self::align(self::words($source), self::words($view)) as [$from, $to, $ok]) {
            if (! $ok && $from === []) {
                $added += count($to);
            }
        }

        return $added;
    }

    /**
     * The view with each word that failed replaced by [$plain] of the original's word (a plain transliteration for Roman
     * Urdu, the word itself for Urdu letters), and words the view added left out. Every original word is then there.
     *
     * @param  callable(string): string  $plain
     */
    public static function repair(string $source, string $view, callable $plain): string
    {
        $original = self::words($source);
        $words = self::words($view);
        $out = [];
        foreach (self::align($original, $words) as [$from, $to, $ok]) {
            if ($ok) {
                foreach ($to as $j) {
                    $out[] = $words[$j];
                }
            } else {
                foreach ($from as $i) {
                    $out[] = $plain($original[$i]);
                }
            }
        }

        return implode(' ', $out);
    }

    /**
     * The words of a line: split at spaces, a marker ("[unclear: two words]") one word, tokens without a letter or digit
     * ("—", "،") left out.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/\[[^\]]*\]\S*|\S+/u', trim($text), $m);

        return array_values(array_filter($m[0], fn (string $w): bool => preg_match('/[\p{L}\p{N}$£€]/u', $w) === 1));
    }

    /** A word's consonant skeleton in Latin letters ("#U" for an [unclear] marker). */
    public static function skeleton(string $word): string
    {
        if (preg_match('/^\[unclear/u', $word) === 1) {
            return self::MARKER;
        }
        $word = (string) preg_replace('/^\[\?(.*)\]$/u', '$1', $word);
        // A number is its digits: "2,500" and "۲۵۰۰" are the same, "16" and "17" are not.
        if (self::isDigits($word)) {
            return '#'.self::digitsOf($word);
        }
        $word = (string) preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        if (preg_match('/^[A-Z]{2,5}s?$/', $word) === 1) {
            return self::squeeze(implode('', array_map(fn (string $c): string => self::LETTER_NAMES[$c] ?? '', str_split(rtrim($word, 's')))));
        }
        $out = '';
        $latin = '';
        foreach (mb_str_split($word) as $char) {
            if (isset(self::URDU[$char])) {
                $out .= self::latin($latin).self::URDU[$char];
                $latin = '';
            } elseif (preg_match('/[a-zA-Z]/', $char) === 1) {
                $latin .= strtolower($char);
            }
        }

        return self::squeeze($out.self::latin($latin));
    }

    /** A word in plain Roman letters, letter by letter (when the view's word did not keep the original's sound). */
    public static function plainRoman(string $word): string
    {
        if (preg_match('/\p{Arabic}/u', $word) !== 1) {
            return $word;
        }
        $map = [
            'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ٹ' => 't', 'ط' => 't', 'ث' => 's', 'س' => 's', 'ص' => 's', 'ج' => 'j', 'چ' => 'ch',
            'خ' => 'kh', 'د' => 'd', 'ڈ' => 'd', 'ذ' => 'z', 'ز' => 'z', 'ض' => 'z', 'ظ' => 'z', 'ژ' => 'zh', 'ر' => 'r', 'ڑ' => 'r',
            'ش' => 'sh', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ک' => 'k', 'ك' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'ں' => 'n', 'ح' => 'h', 'ھ' => 'h', 'آ' => 'aa', 'ا' => 'a', 'ع' => 'a', 'ے' => 'e', 'ۓ' => 'e', 'ء' => '', 'ئ' => '',
            'أ' => 'a', 'إ' => 'i', 'ؤ' => 'o', 'ۃ' => 'h', 'ة' => 'h', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '،' => ',', '۔' => '.', '؟' => '?',
        ];
        $chars = mb_str_split($word);
        $out = '';
        foreach ($chars as $i => $char) {
            $last = $i === count($chars) - 1;
            // Urdu leaves short vowels out: an "a" after a first consonant followed by another ("کل" → "kal").
            if ($i === 1 && isset(self::URDU[$chars[0]], self::URDU[$char])) {
                $out .= 'a';
            }
            $out .= match ($char) {
                'و' => $i === 0 ? 'w' : 'o',
                'ی', 'ي', 'ى' => $last ? 'i' : ($i === 0 ? 'y' : 'e'),
                'ہ', 'ه', 'ۂ' => $last && $i > 0 ? 'a' : 'h',
                default => $map[$char] ?? (preg_match('/[\x{064B}-\x{065F}\x{0670}]/u', $char) === 1 ? '' : $char),
            };
        }

        return $out;
    }

    /**
     * Lines the original's words ([$a]) up with the view's ([$b]): groups of [original word indexes, view word indexes,
     * whether they sound the same], in order; an empty side is a word dropped or added.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: list<int>, 1: list<int>, 2: bool}>
     */
    public static function align(array $a, array $b): array
    {
        $key = sha1(json_encode([$a, $b], JSON_UNESCAPED_UNICODE) ?: '');
        if (isset(self::$aligned[$key])) {
            return self::$aligned[$key];
        }
        if (count(self::$aligned) >= 16) {
            self::$aligned = [];
        }

        return self::$aligned[$key] = self::lineUp($a, $b);
    }

    /** @var array<string, list<array{0: list<int>, 1: list<int>, 2: bool}>> the last few alignments (a line is checked, then mended) */
    private static array $aligned = [];

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: list<int>, 1: list<int>, 2: bool}>
     */
    private static function lineUp(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $sa = array_map(self::skeleton(...), $a);
        $sb = array_map(self::skeleton(...), $b);
        $numA = array_map(fn (string $w): bool => self::isNumberish($w), $a);
        $numB = array_map(fn (string $w): bool => self::isDigits($w), $b);
        $currencyA = array_map(fn (string $w): bool => in_array(self::lower($w), self::CURRENCY_WORDS, true), $a);
        $currencyB = array_map(fn (string $w): bool => in_array(self::lower($w), self::CURRENCY_SIGNS, true), $b);
        $moneyA = in_array(true, $currencyA, true);
        $moneyB = in_array(true, $currencyB, true);

        // Only near the diagonal: a line's words stay in order, so the view's word for the original's i-th is never far
        // from where the lengths put it (a long line stays quick).
        $band = 6 + abs($n - $m);
        $cost = [0 => [0 => 0.0]];
        $back = [];
        for ($i = 0; $i <= $n; $i++) {
            $center = $n > 0 ? intdiv($i * $m, $n) : 0;
            for ($j = max(0, $center - $band); $j <= min($m, $center + $band); $j++) {
                if (! isset($cost[$i][$j])) {
                    continue;
                }
                $here = $cost[$i][$j];
                $try = function (int $di, int $dj, float $c, bool $ok) use (&$cost, &$back, $i, $j, $here): void {
                    if (($cost[$i + $di][$j + $dj] ?? INF) > $here + $c) {
                        $cost[$i + $di][$j + $dj] = $here + $c;
                        $back[$i + $di][$j + $dj] = [$i, $j, $ok];
                    }
                };
                // A word dropped, or added (free for a currency that moved).
                if ($i < $n) {
                    $try(1, 0, $currencyA[$i] && $moneyB ? 0.0 : 10.0, $currencyA[$i] && $moneyB);
                }
                if ($j < $m) {
                    $try(0, 1, $currencyB[$j] && $moneyA ? 0.0 : 10.0, $currencyB[$j] && $moneyA);
                }
                // One word to one, two or three, and two or three to one.
                foreach ([[1, 1], [1, 2], [2, 1], [1, 3], [3, 1]] as [$di, $dj]) {
                    if ($i + $di > $n || $j + $dj > $m) {
                        continue;
                    }
                    $left = implode('', array_slice($sa, $i, $di));
                    $right = implode('', array_slice($sb, $j, $dj));
                    // Words joined or split must sound alike almost exactly, so an added word cannot hide in its neighbour.
                    $ok = $di + $dj === 2 ? self::sameSound($left, $right) : self::distance($left, $right) <= 0.5;
                    $distance = self::distance($left, $right) / max(1, strlen($left), strlen($right));
                    $try($di, $dj, ($ok ? $distance : 10.0 * ($di + $dj)) + ($di + $dj - 2) * 0.05, $ok);
                }
                // Number words (and their units) written as one or two groups of digits.
                if ($i < $n && $j < $m && $numA[$i] && $numB[$j]) {
                    for ($di = 1; $di <= 6 && $i + $di <= $n && $numA[$i + $di - 1]; $di++) {
                        for ($dj = 1; $dj <= 2 && $j + $dj <= $m && $numB[$j + $dj - 1]; $dj++) {
                            // Digits already in the original stay the same digits.
                            $digits = self::digitsOf(implode(' ', array_slice($a, $i, $di)));
                            if ($digits === '' || $digits === self::digitsOf(implode(' ', array_slice($b, $j, $dj)))) {
                                $try($di, $dj, 0.01, true);
                            }
                        }
                    }
                }
            }
        }

        $groups = [];
        for ($i = $n, $j = $m; $i > 0 || $j > 0;) {
            [$pi, $pj, $ok] = $back[$i][$j];
            $groups[] = [$i === $pi ? [] : range($pi, $i - 1), $j === $pj ? [] : range($pj, $j - 1), $ok];
            [$i, $j] = [$pi, $pj];
        }

        return array_reverse($groups);
    }

    /** Two skeletons sound the same: a marker only as a marker, short words exactly, longer ones within about a third. */
    public static function sameSound(string $a, string $b): bool
    {
        if ($a === self::MARKER || $b === self::MARKER || str_contains($a, '#') || str_contains($b, '#')) {
            return $a === $b;
        }
        $longest = max(strlen($a), strlen($b));
        // One or two consonants: only a near letter ("s" for "z"); three: one letter; longer: about a third.
        $allowed = $longest <= 2 ? 0.5 : max(1.0, round($longest * 0.3));

        return self::distance($a, $b) <= $allowed;
    }

    /** Edit distance with half a step for letters that sound alike (s and z, j and g…). */
    private static function distance(string $a, string $b): float
    {
        $n = strlen($a);
        $m = strlen($b);
        $row = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $previous = $row;
            $row = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $swap = $a[$i - 1] === $b[$j - 1] ? 0.0 : (in_array($a[$i - 1].$b[$j - 1], self::NEAR, true) ? 0.5 : 1.0);
                $row[$j] = min($previous[$j] + 1, $row[$j - 1] + 1, $previous[$j - 1] + $swap);
            }
        }

        return (float) $row[$m];
    }

    /** Latin letters to the skeleton's consonants: digraphs first, then vowels, h, w and y left out. */
    private static function latin(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = strtr($text, ['sh' => 'S', 'ch' => 'C', 'ph' => 'f', 'zh' => 'z', 'ck' => 'k', 'kh' => 'k', 'gh' => 'g', 'q' => 'k', 'x' => 'ks']);
        $text = (string) preg_replace('/c(?=[eiy])/', 's', $text);
        $text = str_replace(['c', 'v'], ['k', ''], $text);

        return (string) preg_replace('/[aeiouyhw]/', '', $text);
    }

    private static function squeeze(string $skeleton): string
    {
        return (string) preg_replace('/(.)\1+/', '$1', $skeleton);
    }

    private static function lower(string $word): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}$£€.]/u', '', $word));
    }

    /** The digits of a word in Latin digits ("۲,۵۰۰" → "2500"). */
    private static function digitsOf(string $word): string
    {
        $word = strtr($word, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return (string) preg_replace('/[^0-9]/', '', $word);
    }

    /** A view's word made of digits ("16", "2,500", "3:30", "16GB", "Rs2,500"). */
    private static function isDigits(string $word): bool
    {
        return preg_match('/[0-9۰-۹٠-٩]/u', $word) === 1;
    }

    /** An original word that is a number: digits, a number word or a word that goes with one. */
    private static function isNumberish(string $word): bool
    {
        if (self::isDigits($word)) {
            return true;
        }
        $plain = mb_strtolower((string) preg_replace('/[^\p{L}\']/u', '', $word));
        if ($plain === '') {
            return false;
        }

        return in_array($plain, self::NUMBER_WORDS, true) || in_array($plain, self::NUMBER_COMPANIONS, true) || in_array($plain, self::CURRENCY_WORDS, true);
    }
}
