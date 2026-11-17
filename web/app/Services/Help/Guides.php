<?php

declare(strict_types=1);

namespace App\Services\Help;

use Illuminate\Support\Str;

/**
 * In-app help (v1.8 P6.4): the user guide, one guide per section ("## …"), searched by its words. The guide is
 * docs/USER_GUIDE.md, copied to resources/help/user-guide.md because only web/ is deployed (a test keeps the two the
 * same).
 */
final class Guides
{
    public const PATH = 'help/user-guide.md';

    /** @var list<array{id: string, title: string, markdown: string}>|null */
    private ?array $sections = null;

    /**
     * Every section of the guide, in its order.
     *
     * @return list<array{id: string, title: string, markdown: string}>
     */
    public function all(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }
        $text = (string) @file_get_contents(resource_path(self::PATH));
        $sections = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^## (.+)$/u', $line, $m) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                // "1. Install the Windows app" → "Install the Windows app"; the number stays out of its id too.
                $title = trim((string) preg_replace('/^\d+[a-z]?\.\s+/u', '', $m[1]));
                $current = ['id' => self::uniqueId(Str::slug($title), $sections), 'title' => $title, 'markdown' => ''];

                continue;
            }
            if ($current !== null) {
                $current['markdown'] .= $line."\n";
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        return $this->sections = array_map(fn (array $s): array => [...$s, 'markdown' => trim($s['markdown'])], $sections);
    }

    /** @return array{id: string, title: string, markdown: string}|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }

        return null;
    }

    /**
     * The sections that match [$query], best first (its words in the title count most), at most [$limit]; every
     * section, in the guide's order, without a query.
     *
     * @return list<array{id: string, title: string, excerpt: string}>
     */
    public function search(?string $query, int $limit = 20): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim((string) $query))) ?: [], fn (string $w): bool => mb_strlen($w) >= 2));
        $scored = [];
        foreach ($this->all() as $i => $section) {
            $title = mb_strtolower($section['title']);
            $body = mb_strtolower(self::plain($section['markdown']));
            $score = 0;
            foreach ($words as $word) {
                $inTitle = substr_count($title, $word);
                $inBody = substr_count($body, $word);
                if ($inTitle + $inBody === 0) {
                    $score = -1; // every word must be there

                    break;
                }
                $score += $inTitle * 10 + min($inBody, 20);
            }
            if ($score >= 0) {
                $scored[] = [$score, $i, ['id' => $section['id'], 'title' => $section['title'], 'excerpt' => self::excerpt($section['markdown'], $words[0] ?? null)]];
            }
        }
        usort($scored, fn (array $a, array $b): int => $words === [] ? $a[1] <=> $b[1] : [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        // Every section without a query; the best [$limit] with one.
        return $words === [] ? array_column($scored, 2) : array_slice(array_column($scored, 2), 0, $limit);
    }

    /** About 200 characters of the section's text, around [$word] when it is there. */
    private static function excerpt(string $markdown, ?string $word): string
    {
        $plain = self::plain($markdown);
        $at = $word !== null ? mb_stripos($plain, $word) : false;
        $start = $at === false ? 0 : max(0, $at - 60);
        $piece = mb_substr($plain, $start, 200);

        return ($start > 0 ? '…' : '').trim($piece).(mb_strlen($plain) > $start + 200 ? '…' : '');
    }

    /** The section as plain text: no Markdown marks, one line. */
    private static function plain(string $markdown): string
    {
        $text = (string) preg_replace(['/`([^`]*)`/u', '/\*\*([^*]+)\*\*/u', '/\[([^\]]+)\]\([^)]*\)/u', '/^[#>\-*]+\s*/mu'], ['$1', '$1', '$1', ''], $markdown);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param  list<array{id: string}>  $taken */
    private static function uniqueId(string $id, array $taken): string
    {
        $id = $id === '' ? 'section' : $id;
        $ids = array_column($taken, 'id');
        $candidate = $id;
        for ($n = 2; in_array($candidate, $ids, true); $n++) {
            $candidate = "{$id}-{$n}";
        }

        return $candidate;
    }
}
