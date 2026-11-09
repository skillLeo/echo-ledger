<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use Normalizer;

/**
 * Settings search (since v1.7): finds individual settings, not only pages, over SettingsRegistry.
 *
 * Matching, in short: the query and every indexed text are normalised (accents, case, Urdu letter forms, punctuation)
 * and split into words; every word of the query must match. A word matches the start of an indexed word, a whole
 * keyword phrase ("dark mode"), a whole Roman Urdu or Urdu word, or, for words of 4 letters or more, the same word
 * with doubled letters folded ("aawaz" = "awaz"). Only when nothing matches at all is a small typo forgiven (one letter for words of 4 to 7
 * letters, two from 8), and never against Roman Urdu or Urdu words, so "pro" can never find "probe".
 *
 * Scores: the title (or an alias) starting with the query 1000, every word in the title 800, with an option 650, with
 * the description 500, with a keyword 400, only the path 300; a forgiven typo costs 300. Then 15 less per level of
 * depth and 40 more for a page. Short ambiguous words are pinned (SettingsRegistry::PINS).
 */
final class SettingsIndex
{
    public const MAX_RESULTS = 30;

    public const MAX_HELP = 5;

    private const TITLE = 1;

    private const OPTION = 2;

    private const DESCRIPTION = 3;

    private const KEYWORD = 4;

    private const PATH = 5;

    private const TIERS = [self::TITLE => 800, self::OPTION => 650, self::DESCRIPTION => 500, self::KEYWORD => 400, self::PATH => 300];

    /** Words never indexed: values ("On", "Off") and the commonest small words of the descriptions. */
    private const STOP = ['on', 'off', 'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'your', 'you', 'is', 'are', 'it', 'with', 'we', 'our', 'at', 'by', 'be', 'from', 'this', 'that', 'when', 'as', 'if', 'its', 'can', 'into', 'each'];

    private const SPELLINGS = ['colour' => 'color', 'colours' => 'colors', 'grey' => 'gray', 'organise' => 'organize', 'cancelled' => 'canceled'];

    /** @var list<array<string, mixed>> */
    private array $index = [];

    /**
     * @param  list<array<string, mixed>>  $entries  the entries available to the person, in registry order
     */
    public function __construct(private readonly array $entries)
    {
        foreach ($entries as $order => $entry) {
            $this->index[] = $this->indexEntry($entry, $order);
        }
    }

    /**
     * The results for [$query]: `settings` (at most 30) and `help` (at most 5), best first, each `{entry, score}`;
     * `closest` (up to 3 entries) when nothing matched.
     *
     * @return array{settings: list<array{entry: array<string, mixed>, score: int}>, help: list<array{entry: array<string, mixed>, score: int}>, closest: list<array<string, mixed>>}
     */
    public function search(string $query): array
    {
        $normal = self::normalize($query);
        $empty = ['settings' => [], 'help' => [], 'closest' => []];
        if (mb_strlen($normal) < 2) {
            return $empty;
        }
        // Small words ("on", "the") are not indexed, so they are not asked for either; numbers stay ("2 step").
        $tokens = array_values(array_filter(explode(' ', $normal), fn (string $w): bool => $w !== '' && $w !== '—' && ! in_array($w, self::STOP, true)));
        if ($tokens === []) {
            return $empty;
        }

        $scored = $this->score($normal, $tokens, fuzzy: false);
        if ($scored === []) {
            $scored = $this->score($normal, $tokens, fuzzy: true);
        }
        usort($scored, fn (array $a, array $b): int => [$b['score'], mb_strlen((string) $a['entry']['title']), $a['order']] <=> [$a['score'], mb_strlen((string) $b['entry']['title']), $b['order']]);

        $pinned = [];
        foreach (SettingsRegistry::PINS[$normal] ?? [] as $id) {
            foreach ($this->index as $item) {
                if ($item['entry']['id'] === $id) {
                    $pinned[$id] = ['entry' => $item['entry'], 'score' => 2000];
                }
            }
        }
        $settings = array_values($pinned);
        $help = [];
        foreach ($scored as $hit) {
            $id = (string) $hit['entry']['id'];
            if (isset($pinned[$id])) {
                continue;
            }
            if ($hit['entry']['kind'] === 'help') {
                $help[] = ['entry' => $hit['entry'], 'score' => $hit['score']];
            } else {
                $settings[] = ['entry' => $hit['entry'], 'score' => $hit['score']];
            }
        }
        $settings = array_slice($settings, 0, self::MAX_RESULTS);
        $help = array_slice($help, 0, self::MAX_HELP);

        return ['settings' => $settings, 'help' => $help, 'closest' => $settings === [] && $help === [] ? $this->closest($tokens[0]) : []];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{entry: array<string, mixed>, score: int, order: int}>
     */
    private function score(string $normal, array $tokens, bool $fuzzy): array
    {
        $out = [];
        foreach ($this->index as $item) {
            $query = $tokens;
            $penalty = 0;
            if ($fuzzy) {
                $query = $this->corrected($item, $tokens);
                if ($query === null) {
                    continue;
                }
                $penalty = 300;
            }
            $tier = $this->tier($item, $fuzzy ? implode(' ', $query) : $normal, $query);
            if ($tier === null) {
                continue;
            }
            $score = $tier - $penalty - 15 * (int) $item['depth'] + ($item['entry']['kind'] === 'page' ? 40 : 0);
            $out[] = ['entry' => $item['entry'], 'score' => $score, 'order' => $item['order']];
        }

        return $out;
    }

    /**
     * The tier an entry reaches for the query, or null when a word matches nothing.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $tokens
     */
    private function tier(array $item, string $normal, array $tokens): ?int
    {
        foreach ($item['titles'] as $title) {
            if (str_starts_with($title, $normal)) {
                return 1000;
            }
        }
        $best = array_fill(0, count($tokens), null);
        // Whole keyword phrases ("dark mode", "two step") cover their words at the keyword tier.
        for ($i = 0; $i < count($tokens); $i++) {
            for ($j = count($tokens); $j > $i + 1; $j--) {
                if (in_array(implode(' ', array_slice($tokens, $i, $j - $i)), $item['phrases'], true)) {
                    for ($k = $i; $k < $j; $k++) {
                        $best[$k] = self::KEYWORD;
                    }
                }
            }
        }
        foreach ($tokens as $n => $token) {
            $short = mb_strlen($token) === 1;
            foreach ([self::TITLE, self::OPTION, self::DESCRIPTION, self::KEYWORD, self::PATH] as $field) {
                if ($short && $field !== self::TITLE) {
                    break;
                }
                $matches = self::matchesAny($token, $item['fields'][$field])
                    || ($field === self::KEYWORD && self::matchesWhole($token, $item['local']));
                if (($best[$n] === null || $field < $best[$n]) && $matches) {
                    $best[$n] = $field;
                    break;
                }
            }
            if ($best[$n] === null) {
                return null;
            }
        }

        return self::TIERS[max($best)];
    }

    /**
     * The query with each word that matches nothing replaced by the nearest title, option or English keyword word
     * within the typo allowance; null when a word cannot be placed.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $tokens
     * @return list<string>|null
     */
    private function corrected(array $item, array $tokens): ?array
    {
        $changed = false;
        foreach ($tokens as $n => $token) {
            $all = array_merge(...array_values($item['fields']));
            if (self::matchesAny($token, $all) || self::matchesWhole($token, $item['local'])) {
                continue;
            }
            $length = mb_strlen($token);
            if ($length < 4) {
                return null;
            }
            $allowed = $length >= 8 ? 2 : 1;
            $found = null;
            foreach ($item['fuzzy'] as $candidate) {
                $distance = self::distance($token, $candidate);
                if ($distance <= $allowed && ($found === null || $distance < $found[1])) {
                    $found = [$candidate, $distance];
                }
            }
            if ($found === null) {
                return null;
            }
            $tokens[$n] = $found[0];
            $changed = true;
        }

        return $changed ? $tokens : null;
    }

    /**
     * Up to 3 real entries whose title or English keyword words are close to [$token] (never Roman Urdu or Urdu).
     *
     * @return list<array<string, mixed>>
     */
    private function closest(string $token): array
    {
        $length = mb_strlen($token);
        if ($length < 3) {
            return [];
        }
        $allowed = $length >= 8 ? 3 : ($length >= 5 ? 2 : 1);
        $found = [];
        foreach ($this->index as $item) {
            if ($item['entry']['kind'] === 'help') {
                continue;
            }
            $best = null;
            foreach ($item['near'] as $candidate) {
                $distance = self::distance($token, $candidate);
                if ($distance <= $allowed && ($best === null || $distance < $best)) {
                    $best = $distance;
                }
            }
            if ($best !== null) {
                $found[] = [$best, $item['order'], $item['entry']];
            }
        }
        usort($found, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $f): array => $f[2], array_slice($found, 0, 3));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function indexEntry(array $entry, int $order): array
    {
        $titles = array_values(array_filter(array_map(self::normalize(...), [(string) $entry['title'], ...array_map('strval', (array) $entry['aliases'])])));
        $titleTokens = self::tokensOf($titles);
        $options = in_array($entry['kind'], ['choice', 'segmented'], true) ? array_map('strval', (array) $entry['options']) : [];
        $optionTokens = self::tokensOf($options);
        $english = array_map('strval', (array) $entry['keywords']);
        $other = [...array_map('strval', (array) $entry['ru']), ...array_map('strval', (array) $entry['ur'])];
        $phrases = [];
        foreach ([...$english, ...$other] as $keyword) {
            $normal = self::normalize($keyword);
            if (str_contains($normal, ' ')) {
                $phrases[] = $normal;
            }
        }

        return [
            'entry' => $entry,
            'order' => $order,
            'depth' => self::depth($entry),
            'titles' => $titles,
            'phrases' => $phrases,
            'fields' => [
                self::TITLE => $titleTokens,
                self::OPTION => $optionTokens,
                self::DESCRIPTION => self::tokensOf([(string) $entry['description']]),
                self::KEYWORD => self::tokensOf($english),
                self::PATH => self::tokensOf(array_slice(self::path($entry), 0, -1)),
            ],
            // Roman Urdu and Urdu words match whole (or with doubled letters folded), never by their start: "dark" is
            // not "darkhwast".
            'local' => self::tokensOf($other),
            'fuzzy' => array_values(array_unique([...$titleTokens, ...$optionTokens, ...self::tokensOf($english)])),
            'near' => array_values(array_unique([...$titleTokens, ...self::tokensOf($english)])),
        ];
    }

    /**
     * Where an entry sits: the root section, the page and the entry's own title (["App", "Appearance", "Theme"]); a
     * page or a root row ends with its own title (["App", "Appearance"]).
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    public static function path(array $entry): array
    {
        $page = $entry['page'] ?? null;
        $title = (string) $entry['title'];
        if ($page === null) {
            return ['Settings', $title];
        }
        $section = SettingsRegistry::sectionOf((string) $page) ?? 'Settings';
        if (self::isRoot($entry)) {
            return [$section, $title];
        }

        return [$section, SettingsRegistry::pageTitle((string) $page), $title];
    }

    /**
     * The breadcrumb a result shows: "Settings › Appearance › Theme" ("Settings › Appearance" for the page itself).
     *
     * @param  array<string, mixed>  $entry
     */
    public static function breadcrumb(array $entry): string
    {
        if (($entry['kind'] ?? null) === 'help') {
            return 'Settings › Help';
        }
        $path = self::path($entry);
        $path[0] = 'Settings';
        if (count($path) === 2 && $entry['page'] === null) {
            $path = [$path[0], $path[1]];
        }

        return implode(' › ', $path);
    }

    /**
     * The title as HTML with the matched starts of its words in <mark> ("<mark>Lock</mark> after").
     */
    public static function marked(string $title, string $query): string
    {
        $tokens = array_values(array_filter(explode(' ', self::normalize($query))));
        $out = [];
        foreach (preg_split('/(\s+)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            $normal = self::normalize($part);
            $length = 0;
            foreach ($tokens as $token) {
                if ($normal !== '' && str_starts_with($normal, $token)) {
                    $length = max($length, mb_strlen($token));
                }
            }
            $out[] = $length > 0 ? '<mark>'.e(mb_substr($part, 0, $length)).'</mark>'.e(mb_substr($part, $length)) : e($part);
        }

        return implode('', $out);
    }

    /** @param  array<string, mixed>  $entry */
    public static function isRoot(array $entry): bool
    {
        $page = $entry['page'] ?? null;

        return $page === null || (SettingsRegistry::PAGES[(string) $page] ?? null) === $entry['id'];
    }

    /** @param  array<string, mixed>  $entry */
    private static function depth(array $entry): int
    {
        return self::isRoot($entry) ? 1 : 2;
    }

    /** NFKD without marks, lower case, Urdu letter forms folded, punctuation as spaces, one space between words. */
    public static function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KD) ?: $text;
        $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
        $text = mb_strtolower($text);
        $text = strtr($text, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ه' => 'ہ', 'ة' => 'ہ', "\u{0640}" => '', "\u{200C}" => '', "\u{200D}" => '']);
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = (string) preg_replace('/[&,.·›\/()\-\'"‘’“”!?:;،]+/u', ' ', $text);
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        return implode(' ', array_map(fn (string $w): string => self::SPELLINGS[$w] ?? $w, array_filter($words, fn (string $w): bool => $w !== '')));
    }

    /** @return list<string> the indexed words of a normalised text: no small words, values or bare numbers */
    public static function tokens(string $normal): array
    {
        $out = [];
        foreach (explode(' ', $normal) as $word) {
            if ($word === '' || $word === '—' || in_array($word, self::STOP, true) || ctype_digit($word)) {
                continue;
            }
            $out[] = $word;
        }

        return $out;
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     */
    private static function tokensOf(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            foreach (self::tokens(self::normalize($text)) as $token) {
                $out[] = $token;
            }
        }

        return array_values(array_unique($out));
    }

    /** Doubled Latin letters folded, for words of 4 letters or more ("aawaz" → "awaz"). */
    public static function fold(string $token): string
    {
        return mb_strlen($token) >= 4 ? (string) preg_replace('/([a-z])\1+/', '$1', $token) : $token;
    }

    /** @param  list<string>  $tokens */
    private static function matchesAny(string $query, array $tokens): bool
    {
        $folded = self::fold($query);
        foreach ($tokens as $token) {
            if (str_starts_with($token, $query) || (mb_strlen($query) >= 4 && self::fold($token) === $folded)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $tokens */
    private static function matchesWhole(string $query, array $tokens): bool
    {
        $folded = self::fold($query);
        foreach ($tokens as $token) {
            if ($token === $query || self::fold($token) === $folded) {
                return true;
            }
        }

        return false;
    }

    /** Damerau-Levenshtein distance (optimal string alignment), by characters. */
    public static function distance(string $a, string $b): int
    {
        $s = mb_str_split($a);
        $t = mb_str_split($b);
        $n = count($s);
        $m = count($t);
        if (abs($n - $m) > 3) {
            return 99;
        }
        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i] = [$i];
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $s[$i - 1] === $t[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $s[$i - 1] === $t[$j - 2] && $s[$i - 2] === $t[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m];
    }
}
