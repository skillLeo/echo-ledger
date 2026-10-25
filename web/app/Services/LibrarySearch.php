<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search over titles and transcripts (docs/API.md "GET /api/search", since v1.1). MySQL / MariaDB use the
 * FULLTEXT index on `transcripts.text` in boolean mode; terms too short for it, a query the index answers with
 * nothing, and other databases use LIKE. Every hit points at a segment, so clients can jump to that moment.
 */
final class LibrarySearch
{
    public const MAX_HITS = 3;

    public const SNIPPET_CHARS = 160;

    /** InnoDB's default `innodb_ft_min_token_size`. */
    private const FULLTEXT_MIN_TERM = 3;

    /** @var 'fulltext'|'like' */
    public string $engine = 'like';

    /**
     * @param  array{client_id?: string|null, source_app?: string|null, source_device?: string|null, from?: string|null,
     *     to?: string|null, tag?: string|null, bookmarked?: bool, status?: string|null}  $filters
     * @return array{items: Collection<int, CaptureSession>, hits: array<string, array{hits: list<array<string, mixed>>, count: int}>, more: bool}
     */
    public function search(int $userId, string $query, array $filters = [], int $limit = 20, int $page = 1): array
    {
        $terms = self::terms($query);
        $base = $this->filtered($userId, $filters);
        $this->engine = 'like';
        $matching = (clone $base)->where(fn (Builder $q) => $q
            ->whereHas('transcripts', fn (Builder $t) => $this->likeAll($t, 'text', $terms))
            ->orWhere(fn (Builder $title) => $this->likeAll($title, 'title', $terms)));
        if ($this->fulltextUsable($terms)) {
            $fulltext = (clone $base)->where(fn (Builder $q) => $q
                ->whereHas('transcripts', fn (Builder $t) => $t->whereFullText('text', self::booleanQuery($terms), ['mode' => 'boolean']))
                ->orWhere(fn (Builder $title) => $this->likeAll($title, 'title', $terms)));
            // The engine is chosen for the whole query, never per page, so paging stays consistent.
            if ((clone $fulltext)->exists()) {
                $matching = $fulltext;
                $this->engine = 'fulltext';
            }
        }
        $sessions = $this->page($matching, $limit, $page);

        $more = $sessions->count() > $limit;
        $items = $sessions->take($limit)->values();
        $hits = [];
        foreach ($items as $session) {
            $hits[$session->id] = self::hitsFor($session, $terms);
        }

        return ['items' => $items, 'hits' => $hits, 'more' => $more];
    }

    /**
     * The words to find: quoted phrases stay together, other words split on spaces; at most 8, each ≤ 64 characters.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        preg_match_all('/"([^"]+)"|(\S+)/u', $query, $matches, PREG_SET_ORDER);
        $terms = [];
        foreach ($matches as $m) {
            $term = trim(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
            $term = trim($term, " \t\n\r\0\x0B\"'+-*()<>~@");
            if ($term !== '' && ! in_array(mb_strtolower($term), array_map('mb_strtolower', $terms), true)) {
                $terms[] = mb_substr($term, 0, 64);
            }
        }

        return array_slice($terms, 0, 8);
    }

    /**
     * Up to [MAX_HITS] segments of the session's transcripts (final first) that contain every term, with a snippet and
     * the character ranges to highlight. A title-only match has no hits.
     *
     * @param  list<string>  $terms
     * @return array{hits: list<array<string, mixed>>, count: int}
     */
    public static function hitsFor(CaptureSession $session, array $terms): array
    {
        $transcripts = $session->transcripts->sortBy(fn (Transcript $t): int => $t->kind === TranscriptKind::Final ? 0 : 1);
        $hits = [];
        $count = 0;
        $seen = [];
        foreach ($transcripts as $transcript) {
            foreach ($transcript->segments ?? [] as $index => $segment) {
                $text = (string) ($segment['text'] ?? '');
                $key = ($segment['start_ms'] ?? 0).'|'.mb_strtolower($text);
                if ($text === '' || isset($seen[$key]) || ! self::containsAll($text, $terms)) {
                    continue;
                }
                $seen[$key] = true; // the live captions repeat what the final transcript already found
                $count++;
                if (count($hits) < self::MAX_HITS) {
                    [$snippet, $ranges] = self::snippet($text, $terms);
                    $hits[] = [
                        'transcript_id' => $transcript->id,
                        'kind' => $transcript->kind->value,
                        'segment_index' => (int) $index,
                        'start_ms' => (int) ($segment['start_ms'] ?? 0),
                        'end_ms' => (int) ($segment['end_ms'] ?? 0),
                        'speaker' => (string) ($segment['speaker'] ?? ''),
                        'snippet' => $snippet,
                        'highlights' => $ranges,
                    ];
                }
            }
        }

        return ['hits' => $hits, 'count' => $count];
    }

    /**
     * The text around the first match, at most [SNIPPET_CHARS] characters with "…" where it was cut, and the
     * [start, length] character ranges of every term inside it.
     *
     * @param  list<string>  $terms
     * @return array{0: string, 1: list<array{0: int, 1: int}>}
     */
    public static function snippet(string $text, array $terms): array
    {
        $lower = mb_strtolower($text);
        $first = mb_strlen($text);
        foreach ($terms as $term) {
            $at = mb_strpos($lower, mb_strtolower($term));
            if ($at !== false) {
                $first = min($first, $at);
            }
        }
        $length = mb_strlen($text);
        $start = $length <= self::SNIPPET_CHARS ? 0 : max(0, min($first - 40, $length - self::SNIPPET_CHARS));
        $cut = mb_substr($text, $start, self::SNIPPET_CHARS);
        $prefix = $start > 0 ? '…' : '';
        $suffix = $start + self::SNIPPET_CHARS < $length ? '…' : '';
        $snippet = $prefix.$cut.$suffix;

        $ranges = [];
        $snippetLower = mb_strtolower($snippet);
        foreach ($terms as $term) {
            $needle = mb_strtolower($term);
            $offset = 0;
            while (($at = mb_strpos($snippetLower, $needle, $offset)) !== false) {
                $ranges[] = [$at, mb_strlen($needle)];
                $offset = $at + max(1, mb_strlen($needle));
            }
        }
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return [$snippet, $ranges];
    }

    /** @param list<string> $terms */
    private static function containsAll(string $text, array $terms): bool
    {
        $lower = mb_strtolower($text);
        foreach ($terms as $term) {
            if (! str_contains($lower, mb_strtolower($term))) {
                return false;
            }
        }

        return $terms !== [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CaptureSession>
     */
    private function filtered(int $userId, array $filters): Builder
    {
        $query = CaptureSession::query()->where('user_id', $userId)
            ->where('status', '!=', SessionStatus::Recording->value)
            ->with(['device', 'client', 'transcripts', 'markers:id,capture_session_id,tag,at_ms']);
        foreach (['client_id', 'source_app', 'source_device', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('started_at', '>=', Carbon::parse($filters['from'])->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('started_at', '<=', Carbon::parse($filters['to'])->endOfDay()->utc());
        }
        if (! empty($filters['tag'])) {
            $query->whereHas('markers', fn (Builder $q) => $q->where('tag', $filters['tag']));
        }
        if (! empty($filters['bookmarked'])) {
            $query->whereHas('markers');
        }

        return $query;
    }

    /**
     * One more than the page size, newest first, so the caller knows whether another page follows.
     *
     * @param  Builder<CaptureSession>  $query
     * @return Collection<int, CaptureSession>
     */
    private function page(Builder $query, int $limit, int $page): Collection
    {
        return $query->orderByDesc('started_at')->orderByDesc('id')->skip(($page - 1) * $limit)->take($limit + 1)->get();
    }

    /**
     * Every term somewhere in [$column] (LIKE with `!` as the escape character, so `%` and `_` are literal).
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $terms
     */
    private function likeAll(Builder $query, string $column, array $terms): void
    {
        foreach ($terms as $term) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
            $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ['%'.mb_strtolower($escaped).'%']);
        }
    }

    /** @param list<string> $terms */
    private function fulltextUsable(array $terms): bool
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) || $terms === []) {
            return false;
        }
        foreach ($terms as $term) {
            if (mb_strlen($term) < self::FULLTEXT_MIN_TERM) {
                return false;
            }
        }

        return true;
    }

    /**
     * Boolean-mode query: every term required, phrases quoted.
     *
     * @param  list<string>  $terms
     */
    public static function booleanQuery(array $terms): string
    {
        return implode(' ', array_map(
            fn (string $t): string => str_contains($t, ' ') ? '+"'.str_replace('"', '', $t).'"' : '+'.$t,
            $terms,
        ));
    }
}
