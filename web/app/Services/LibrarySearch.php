<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Models\Transcript;
use App\Support\UrduText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search over titles, transcripts and (since v1.5) screen moments (docs/API.md "GET /api/search", since
 * v1.1). MySQL / MariaDB use the FULLTEXT indexes on the folded `search_text` in boolean mode; terms too short for
 * them, a query the indexes answer with nothing, and other databases use LIKE. Every hit points at a segment or a
 * screen's moment, so clients can jump to that moment.
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
     * @param  array{client_id?: string|null, client_ids?: list<string>, source_app?: string|null, source_device?: string|null,
     *     from?: string|null, to?: string|null, tag?: string|null, bookmarked?: bool, status?: string|null}  $filters
     * @return array{items: Collection<int, CaptureSession>, hits: array<string, array{hits: list<array<string, mixed>>, count: int}>, more: bool}
     */
    public function search(int $workspaceId, string $query, array $filters = [], int $limit = 20, int $page = 1): array
    {
        return $this->searchIn($this->filtered($workspaceId, $filters), $query, $limit, $page);
    }

    /**
     * The same search over a query the caller has already limited to what the person may read: the connector's
     * search over what teammates sent them (since v1.5), which the library's search leaves out.
     *
     * @param  Builder<CaptureSession>  $base
     * @return array{items: Collection<int, CaptureSession>, hits: array<string, array{hits: list<array<string, mixed>>, count: int}>, more: bool}
     */
    public function searchIn(Builder $base, string $query, int $limit = 20, int $page = 1): array
    {
        $terms = self::terms($query);
        // Transcripts are searched in their folded form (Urdu spelling variants made one, App\Support\UrduText), with
        // folded terms; titles keep the original letters and the original terms.
        $folded = array_values(array_unique(array_map(UrduText::fold(...), $terms)));
        $this->engine = 'like';
        // Since v1.5 the text and description of screen moments are searched too, like transcripts.
        $matching = (clone $base)->where(fn (Builder $q) => $q
            ->whereHas('transcripts', fn (Builder $t) => $this->likeAll($t, 'search_text', $folded))
            ->orWhereHas('screens', fn (Builder $s) => $this->likeAll($s, 'search_text', $folded))
            ->orWhere(fn (Builder $title) => $this->likeAll($title, 'title', $terms)));
        if ($this->fulltextUsable($folded)) {
            $fulltext = (clone $base)->where(fn (Builder $q) => $q
                ->whereHas('transcripts', fn (Builder $t) => $t->whereFullText('search_text', self::booleanQuery($folded), ['mode' => 'boolean']))
                ->orWhereHas('screens', fn (Builder $s) => $s->whereFullText('search_text', self::booleanQuery($folded), ['mode' => 'boolean']))
                ->orWhere(fn (Builder $title) => $this->likeAll($title, 'title', $terms)));
            // The engine is chosen for the whole query, never per page, so paging stays consistent.
            try {
                if ((clone $fulltext)->exists()) {
                    $matching = $fulltext;
                    $this->engine = 'fulltext';
                }
            } catch (QueryException $e) {
                // LIKE still finds the words; the log shows what the boolean query got wrong.
                report($e);
            }
        }
        $sessions = $this->page($matching, $limit, $page);

        $more = $sessions->count() > $limit;
        $items = $sessions->take($limit)->values();
        $screens = $this->matchingScreens($items->map(fn (CaptureSession $s): string => $s->id)->all(), $folded);
        $hits = [];
        foreach ($items as $session) {
            $hits[$session->id] = self::hitsFor($session, $terms, $screens[$session->id] ?? []);
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
     * the character ranges to highlight, then (since v1.5) its screen moments whose text or description does. A
     * title-only match has no hits.
     *
     * A screen's hit has the shape of a line's, so the apps show it and play from its moment as they do for lines:
     * `kind` "screen", `speaker` "Screen 7", `start_ms` = `end_ms` = its moment, `segment_index` minus its number (it
     * is no line of the transcript), `screen_id`, and `transcript_id` the recording's transcript ("" without one).
     *
     * @param  list<string>  $terms
     * @param  list<array{id: string, index: int, at_ms: int, text: string}>  $screens  the session's screens that matched
     * @return array{hits: list<array<string, mixed>>, count: int}
     */
    public static function hitsFor(CaptureSession $session, array $terms, array $screens = []): array
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
        $first = $transcripts->first();
        $transcriptId = $first instanceof Transcript ? $first->id : '';
        foreach ($screens as $screen) {
            if (! self::containsAll($screen['text'], $terms)) {
                continue;
            }
            $count++;
            if (count($hits) < self::MAX_HITS) {
                [$snippet, $ranges] = self::snippet($screen['text'], $terms);
                $hits[] = [
                    'transcript_id' => $transcriptId,
                    'kind' => 'screen',
                    'screen_id' => $screen['id'],
                    'segment_index' => -$screen['index'],
                    'start_ms' => $screen['at_ms'],
                    'end_ms' => $screen['at_ms'],
                    'speaker' => 'Screen '.$screen['index'],
                    'snippet' => $snippet,
                    'highlights' => $ranges,
                ];
            }
        }

        return ['hits' => $hits, 'count' => $count];
    }

    /**
     * The screens of these sessions whose folded text holds every term, numbered in their recording's time order, as
     * text to show: the description, then the screen's text on one line.
     *
     * @param  list<string>  $sessionIds
     * @param  list<string>  $folded
     * @return array<string, list<array{id: string, index: int, at_ms: int, text: string}>>
     */
    private function matchingScreens(array $sessionIds, array $folded): array
    {
        if ($sessionIds === [] || $folded === []) {
            return [];
        }
        $matches = Screen::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('capture_session_id', $sessionIds)
            ->where(fn (Builder $q) => $this->likeAll($q, 'search_text', $folded))
            ->orderBy('at_ms')->orderBy('id')->get(['id', 'capture_session_id', 'at_ms', 'ocr_text', 'description']);
        if ($matches->isEmpty()) {
            return [];
        }
        $index = [];
        $counter = [];
        foreach (Screen::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('capture_session_id', $matches->pluck('capture_session_id')->unique()->all())
            ->orderBy('at_ms')->orderBy('id')->get(['id', 'capture_session_id']) as $row) {
            $index[$row->id] = $counter[$row->capture_session_id] = ($counter[$row->capture_session_id] ?? 0) + 1;
        }
        $out = [];
        foreach ($matches as $screen) {
            $text = implode(' · ', array_filter([$screen->description, trim((string) preg_replace('/\s+/u', ' ', (string) $screen->ocr_text))], fn (?string $t): bool => $t !== null && $t !== ''));
            $out[$screen->capture_session_id][] = ['id' => $screen->id, 'index' => $index[$screen->id] ?? 1, 'at_ms' => $screen->at_ms, 'text' => $text];
        }

        return $out;
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
        [$folded, $map] = UrduText::foldWithMap($text);
        $first = mb_strlen($text);
        foreach ($terms as $term) {
            $at = mb_strpos($folded, UrduText::fold($term));
            if ($at !== false) {
                $first = min($first, $map[$at] ?? $first);
            }
        }
        $length = mb_strlen($text);
        $start = $length <= self::SNIPPET_CHARS ? 0 : max(0, min($first - 40, $length - self::SNIPPET_CHARS));
        $cut = mb_substr($text, $start, self::SNIPPET_CHARS);
        $prefix = $start > 0 ? '…' : '';
        $suffix = $start + self::SNIPPET_CHARS < $length ? '…' : '';
        $snippet = $prefix.$cut.$suffix;

        // Ranges are found in the folded snippet and mapped back to the snippet's own characters, so a word written
        // with another Yeh or with vowel marks is highlighted over its whole original length.
        $ranges = [];
        [$snippetFolded, $snippetMap] = UrduText::foldWithMap($snippet);
        foreach ($terms as $term) {
            $needle = UrduText::fold($term);
            if ($needle === '') {
                continue;
            }
            $offset = 0;
            while (($at = mb_strpos($snippetFolded, $needle, $offset)) !== false) {
                $from = $snippetMap[$at];
                $to = $snippetMap[$at + mb_strlen($needle) - 1];
                $ranges[] = [$from, $to - $from + 1];
                $offset = $at + max(1, mb_strlen($needle));
            }
        }
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return [$snippet, $ranges];
    }

    /** @param list<string> $terms */
    private static function containsAll(string $text, array $terms): bool
    {
        $lower = UrduText::fold($text);
        foreach ($terms as $term) {
            if (! str_contains($lower, UrduText::fold($term))) {
                return false;
            }
        }

        return $terms !== [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CaptureSession>
     */
    private function filtered(int $workspaceId, array $filters): Builder
    {
        $query = CaptureSession::query()->where('workspace_id', $workspaceId)->ofPerson()
            ->where('status', '!=', SessionStatus::Recording->value)
            ->with(['device', 'client', 'transcripts', 'markers:id,capture_session_id,tag,at_ms'])->withCount('screens');
        foreach (['client_id', 'source_app', 'source_device', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        // Since v1.5: a project and its subfolders (the connector).
        if (isset($filters['client_ids'])) {
            $query->whereIn('client_id', $filters['client_ids']);
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
     * Boolean-mode query: every term required. Only plain words go in bare: inside a word `@`, `-`, `*` and the like are
     * operators, which is a syntax error for an email address and "not this word" for "well-known". A quoted phrase
     * finds the same words in order.
     *
     * @param  list<string>  $terms
     */
    public static function booleanQuery(array $terms): string
    {
        return implode(' ', array_map(
            fn (string $t): string => preg_match('/^[\p{L}\p{N}\p{M}]+$/u', $t) === 1 ? '+'.$t : '+"'.str_replace('"', '', $t).'"',
            $terms,
        ));
    }
}
