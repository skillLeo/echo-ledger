<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\UploadStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\LabelList;
use App\Models\Recording;
use App\Models\SessionLabel;
use App\Models\SessionMarker;
use App\Models\SessionOpen;
use App\Models\Transcript;
use App\Services\LibrarySearch;
use App\Services\Lists\LabelLists;
use App\Services\SettingsRepository;
use ArrayObject;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Library's filters at work (contract 2.0.0-p): which of one person's recordings a filter set and a search keep,
 * how many each option of each section would keep (the facets), and what needs the person. Always one person in one
 * workspace, named by explicit ids: nothing of a teammate or another workspace is ever read.
 *
 * Every recording is read once as a compact row (its values in each section, LibraryFilters) and the filters are
 * matched in PHP, the way the phone and the desktop match them on their synced library, so the three agree by
 * construction. The rows and each filter set's answer are cached under the person's library version: a stamp that every
 * saved recording, transcript, upload, label, bookmark, open, list or project of theirs renews (listen()), with what the
 * recordings say of themselves (how many, the newest change). The stamp is renewed once a request, after it (so after its
 * transactions: a read in between never keeps uncommitted rows under the new stamp); until then this request reads the
 * person's library afresh and keeps nothing of it. A change made some other way is seen within ROWS_TTL at most.
 *
 * @phpstan-type Row array{id: string, started: int, ended: int|null, session_status: string, source: string, device: string, projects: list<string>, language: string, status: string, labels: list<string>, marked: list<string>, bookmarks: list<string>, opened: bool}
 * @phpstan-type Filters array<string, array{op: string, values: list<string>, from?: string, to?: string}>
 */
final class LibraryFacets
{
    /** The sidebar's Library places (the web console's; the apps count theirs from their own library). */
    public const PLACES = ['all', 'needs_you', 'unread', 'important', 'not_transcribed', 'audio_only'];

    /** How long one filter set's counts are kept (seconds); the date and the 24 hours of Needs you move with the clock. */
    private const RESULT_TTL = 60;

    /** How long the rows are kept at most (seconds), however the library changed. */
    private const ROWS_TTL = 120;

    /** @var array<string, mixed> rows and search results already read, by version */
    private array $memo = [];

    /** Where the application keeps, for this request (or job, or command), whose library changed and how often. */
    private const CHANGES = 'library-facets.changes';

    /**
     * Renews the library version of the person whose recording (or project) changed. Registered for the models whose
     * changes move a recording between filters; a child row names its recording, which names the person.
     */
    public static function listen(): void
    {
        $person = fn (?int $workspaceId, ?int $userId) => $workspaceId !== null && $userId !== null ? self::forget($workspaceId, $userId) : null;
        $ofSession = function (mixed $sessionId) use ($person): void {
            $row = is_string($sessionId) ? DB::table('capture_sessions')->where('id', $sessionId)->first(['workspace_id', 'user_id']) : null;
            $person($row === null ? null : (int) $row->workspace_id, $row === null ? null : (int) $row->user_id);
        };
        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            CaptureSession::{$event}(fn (CaptureSession $s) => $person((int) $s->workspace_id, (int) $s->user_id));
        }
        Client::saved(fn (Client $c) => $person((int) $c->workspace_id, (int) $c->user_id));
        Client::deleted(fn (Client $c) => $person((int) $c->workspace_id, (int) $c->user_id));
        // A list's name, colour or place, and a new empty list: the Labels section's options.
        LabelList::saved(fn (LabelList $l) => $person((int) $l->workspace_id, (int) $l->user_id));
        LabelList::deleted(fn (LabelList $l) => $person((int) $l->workspace_id, (int) $l->user_id));
        // An upload saves its recording at every part it receives: only a change of its state moves anything.
        Recording::created(fn (Recording $r) => $ofSession($r->capture_session_id));
        Recording::updated(fn (Recording $r) => $r->wasChanged('upload_status') ? $ofSession($r->capture_session_id) : null);
        Recording::deleted(fn (Recording $r) => $ofSession($r->capture_session_id));
        foreach ([Transcript::class, SessionLabel::class, SessionMarker::class, SessionOpen::class] as $model) {
            $model::saved(fn ($row) => $ofSession($row->getAttribute('capture_session_id')));
            $model::deleted(fn ($row) => $ofSession($row->getAttribute('capture_session_id')));
        }
    }

    /**
     * The person's library changed: this request reads it afresh from now on, and their stamp is renewed once, after the
     * request (deferred, so after every transaction of it, and once however many rows changed).
     */
    public static function forget(int $workspaceId, int $userId): void
    {
        $key = $workspaceId.':'.$userId;
        if (app()->bound('request')) {
            request()->attributes->remove(self::versionKey($workspaceId, $userId));
        }
        $changes = self::changes();
        $changes[$key] = ($changes[$key] ?? 0) + 1;
        // One renewal however many rows change: a deferred callback of the same name replaces the one before.
        defer(fn () => DB::afterCommit(fn () => self::renew($workspaceId, $userId)), 'library-facets:'.$key, always: true);
    }

    /** @return ArrayObject<string, int> */
    private static function changes(): ArrayObject
    {
        if (! app()->bound(self::CHANGES)) {
            app()->instance(self::CHANGES, new ArrayObject);
        }

        /** @var ArrayObject<string, int> */
        return app(self::CHANGES);
    }

    /** Renews the person's stamp: every reader reads their library again. */
    public static function renew(int $workspaceId, int $userId): void
    {
        $changes = self::changes();
        if ($changes->offsetExists($workspaceId.':'.$userId)) {
            $changes->offsetUnset($workspaceId.':'.$userId);
        }
        Cache::forever(self::stampKey($workspaceId, $userId), bin2hex(random_bytes(8)));
        if (app()->bound('request')) {
            request()->attributes->remove(self::versionKey($workspaceId, $userId));
        }
    }

    /** How many changes of the person's library wait for the stamp's renewal in this request (0 when none). */
    public static function pending(int $workspaceId, int $userId): int
    {
        return self::changes()[$workspaceId.':'.$userId] ?? 0;
    }

    private static function stampKey(int $workspaceId, int $userId): string
    {
        return 'library-facets:stamp:'.$workspaceId.':'.$userId;
    }

    private static function versionKey(int $workspaceId, int $userId): string
    {
        return 'el.library-version.'.$workspaceId.'.'.$userId;
    }

    public function __construct(private readonly LibrarySearch $search, private readonly SettingsRepository $settings) {}

    /**
     * GET /api/library/facets: the recordings the filters and the search keep (`total`), and for each section the count
     * of each option with every other section's filter applied but not its own, so an option stays selectable. Then
     * "Needs you" for the whole library, whatever the filters.
     *
     * @param  Filters  $filters
     * @return array{total: int, sections: array<string, list<array<string, mixed>>>, needs_you: array{count: int, failed: int, waiting: int, stuck: int}}
     */
    public function facets(int $workspaceId, int $userId, array $filters, ?string $query = null, ?string $zone = null, ?string $place = null): array
    {
        $zone ??= $this->settings->displayTimezone();
        $query = self::cleanQuery($query);
        $place = self::place($place);
        $version = $this->version($workspaceId, $userId);
        $key = 'library-facets:result:'.$workspaceId.':'.$userId.':'.sha1((string) json_encode([$version, $filters, $query, $zone, $place, intdiv(Carbon::now()->getTimestamp(), self::RESULT_TTL)]));

        return self::kept($version, $key, self::RESULT_TTL, fn (): array => $this->count($workspaceId, $userId, $filters, $query, $zone, $version, $place));
    }

    /**
     * A place of the sidebar's Library (the web console counts and lists within it): `all`, `needs_you`, `unread`,
     * `important`, `not_transcribed` or `audio_only`; anything else is `all`.
     */
    public static function place(?string $place): string
    {
        return in_array($place, self::PLACES, true) ? (string) $place : 'all';
    }

    /**
     * Whether a row is in [$place]: a call still waiting only in Needs you, every other row by its own values.
     *
     * @param  Row  $row
     */
    private static function inPlace(array $row, string $place, int $now): bool
    {
        if ($place === 'needs_you') {
            return LibraryFilters::needsYou($row['session_status'], $row['status'], $row['ended'], $row['started'], $now) !== null;
        }
        if ($row['session_status'] === SessionStatus::Recording->value) {
            return false;
        }

        return match ($place) {
            'unread' => ! $row['opened'],
            'important' => in_array('important', $row['marked'], true),
            'not_transcribed' => $row['status'] === 'not_transcribed',
            'audio_only' => $row['session_status'] === SessionStatus::AudioOnly->value,
            default => true,
        };
    }

    /**
     * The ids of the person's recordings the filters and the search keep (never a call still waiting for its decision);
     * null when nothing is asked (every recording).
     *
     * @param  Filters  $filters
     * @return list<string>|null
     */
    public function matchingIds(int $workspaceId, int $userId, array $filters, ?string $query = null, ?string $zone = null, ?string $place = null): ?array
    {
        $query = self::cleanQuery($query);
        $place = self::place($place);
        if ($filters === [] && $query === null && $place === 'all') {
            return null;
        }
        $zone ??= $this->settings->displayTimezone();
        $rows = $this->searched($workspaceId, $userId, $query, $this->version($workspaceId, $userId));
        $ranges = self::ranges($filters, $zone);
        $now = Carbon::now()->getTimestamp();
        $ids = [];
        foreach ($rows as $row) {
            if (self::inPlace($row, $place, $now) && self::passes($row, $filters, $ranges) === true) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Narrows a query of capture sessions to what the filters and the search keep (the list the counts describe).
     *
     * @param  Builder<CaptureSession>  $builder
     * @param  Filters  $filters
     * @return Builder<CaptureSession>
     */
    public function apply(Builder $builder, int $workspaceId, int $userId, array $filters, ?string $query = null, ?string $zone = null, ?string $place = null): Builder
    {
        $place = self::place($place);
        $ids = $this->matchingIds($workspaceId, $userId, $filters, $query, $zone, $place);
        if ($ids === null) {
            // Every recording: a call still waiting is in Needs you only.
            return $builder->where($builder->qualifyColumn('status'), '!=', SessionStatus::Recording->value);
        }

        return self::restrictTo($builder, $ids, $this->liveIds($workspaceId, $userId, $place === 'needs_you'), $place === 'needs_you');
    }

    /**
     * How many recordings [$filters] keep in [$place] (a saved view's count in the sidebar), without the facets.
     *
     * @param  Filters  $filters
     */
    public function total(int $workspaceId, int $userId, array $filters, ?string $query = null, ?string $zone = null, ?string $place = null): int
    {
        $ids = $this->matchingIds($workspaceId, $userId, $filters, $query, $zone, $place);

        return $ids === null ? count($this->liveIds($workspaceId, $userId)) : count($ids);
    }

    /**
     * "Needs you" for the whole library: how many recordings, and how many for each reason.
     *
     * @return array{count: int, failed: int, waiting: int, stuck: int}
     */
    public function needsYou(int $workspaceId, int $userId): array
    {
        $counts = ['count' => 0, 'failed' => 0, 'waiting' => 0, 'stuck' => 0];
        foreach ($this->needsYouOf($workspaceId, $userId) as $reason) {
            $counts['count']++;
            $counts[$reason]++;
        }

        return $counts;
    }

    /**
     * The sidebar's Library views (counted like the list shows them; Needs you also holds waiting calls).
     *
     * @return array{all: int, needs_you: int, unread: int, important: int, not_transcribed: int, audio_only: int}
     */
    public function libraryCounts(int $workspaceId, int $userId): array
    {
        $counts = ['all' => 0, 'needs_you' => count($this->needsYouOf($workspaceId, $userId)), 'unread' => 0, 'important' => 0, 'not_transcribed' => 0, 'audio_only' => 0];
        foreach ($this->rows($workspaceId, $userId, $this->version($workspaceId, $userId)) as $row) {
            if ($row['session_status'] === SessionStatus::Recording->value) {
                continue;
            }
            $counts['all']++;
            $counts['unread'] += $row['opened'] ? 0 : 1;
            $counts['important'] += in_array('important', $row['marked'], true) ? 1 : 0;
            $counts['not_transcribed'] += $row['status'] === 'not_transcribed' ? 1 : 0;
            $counts['audio_only'] += $row['session_status'] === SessionStatus::AudioOnly->value ? 1 : 0;
        }

        return $counts;
    }

    /**
     * The ids of the person's recordings the search finds (waiting calls too), or null when it holds no word.
     *
     * @return list<string>|null
     */
    public function searchIds(int $workspaceId, int $userId, ?string $query): ?array
    {
        $query = self::cleanQuery($query);
        if ($query === null) {
            return null;
        }

        return array_column($this->searched($workspaceId, $userId, $query, $this->version($workspaceId, $userId)), 'id');
    }

    /** The search as the counts use it: null when it holds no word (under 2 characters, or only punctuation). */
    public static function cleanQuery(?string $query): ?string
    {
        $query = trim((string) $query);

        return mb_strlen($query) < 2 || LibrarySearch::terms($query) === [] ? null : mb_substr($query, 0, 200);
    }

    /**
     * @param  Filters  $filters
     * @return array{total: int, sections: array<string, list<array<string, mixed>>>, needs_you: array{count: int, failed: int, waiting: int, stuck: int}}
     */
    private function count(int $workspaceId, int $userId, array $filters, ?string $query, string $zone, string $version, string $place = 'all'): array
    {
        $now = Carbon::now()->getTimestamp();
        $all = array_values(array_filter($this->rows($workspaceId, $userId, $version), fn (array $r): bool => $r['session_status'] !== SessionStatus::Recording->value));
        $rows = array_values(array_filter($this->searched($workspaceId, $userId, $query, $version), fn (array $r): bool => self::inPlace($r, $place, $now)));
        $ranges = self::ranges($filters, $zone);
        $optionRanges = [];
        foreach (['today', 'last_7_days', 'this_month'] as $date) {
            $optionRanges[$date] = LibraryFilters::dateRange(['values' => [$date]], $zone);
        }

        $counts = array_fill_keys(LibraryFilters::SECTIONS, []);
        $total = 0;
        foreach ($rows as $row) {
            $failed = self::passes($row, $filters, $ranges);
            if ($failed === true) {
                $total++;
            }
            // A row counts in a section's options when every other section keeps it: it failed none, or only that one.
            foreach (LibraryFilters::SECTIONS as $section) {
                if ($failed !== true && $failed !== $section) {
                    continue;
                }
                $values = $section === 'date'
                    ? array_keys(array_filter($optionRanges, fn (array $range): bool => self::within($row['started'], $range)))
                    : self::valuesOf($row, $section);
                foreach ($values as $value) {
                    $counts[$section][$value] = ($counts[$section][$value] ?? 0) + 1;
                }
            }
        }

        return [
            'total' => $total,
            'sections' => $this->options($workspaceId, $userId, $all, $counts, $filters),
            'needs_you' => $this->needsYou($workspaceId, $userId),
        ];
    }

    /**
     * Each section's options in the panel's order with their counts: the fixed options always, every value one of the
     * person's recordings has, every project and list of theirs, and every value the filters name.
     *
     * @param  list<Row>  $all
     * @param  array<string, array<string, int>>  $counts
     * @param  Filters  $filters
     * @return array<string, list<array<string, mixed>>>
     */
    private function options(int $workspaceId, int $userId, array $all, array $counts, array $filters): array
    {
        $present = array_fill_keys(LibraryFilters::SECTIONS, []);
        foreach ($all as $row) {
            foreach (['source', 'device', 'language', 'labels', 'bookmarks'] as $section) {
                foreach (self::valuesOf($row, $section) as $value) {
                    $present[$section][$value] = true;
                }
            }
        }
        foreach ($filters as $section => $filter) {
            foreach ($filter['values'] as $value) {
                $present[$section][$value] = true;
            }
        }
        $ordered = fn (string $section, array $order): array => array_values(array_filter($order, fn (string $v): bool => isset($present[$section][$v])));
        $plain = fn (string $section, array $values): array => array_map(fn (string $v): array => ['value' => $v, 'count' => $counts[$section][$v] ?? 0], $values);

        return [
            'date' => $plain('date', ['today', 'last_7_days', 'this_month']),
            'source' => $plain('source', $ordered('source', LibraryFilters::SOURCES)),
            'device' => $plain('device', $ordered('device', LibraryFilters::DEVICES)),
            'project' => $this->projectOptions($workspaceId, $userId, $counts['project'], $present['project']),
            'language' => $plain('language', $ordered('language', LibraryFilters::languages())),
            'status' => $plain('status', LibraryFilters::STATUSES),
            'labels' => $this->labelOptions($workspaceId, $userId, $counts['labels'], array_map('strval', array_keys($present['labels']))),
            'marked' => $plain('marked', LibraryFilters::MARKED),
            // Any bookmark first, then each tag one of the recordings has (or the filters name), by name.
            'bookmarks' => $plain('bookmarks', [LibraryFilters::ANY_BOOKMARK, ...self::sorted(array_diff(array_map('strval', array_keys($present['bookmarks'])), [LibraryFilters::ANY_BOOKMARK]))]),
        ];
    }

    /**
     * @param  iterable<string>  $values
     * @return list<string>
     */
    private static function sorted(iterable $values): array
    {
        $list = [...$values];
        usort($list, fn (string $a, string $b): int => strcmp($a, $b));

        return $list;
    }

    /**
     * No project first, then the person's projects as a tree (each folder before its subfolders, by name).
     *
     * @param  array<string, int>  $counts
     * @param  array<string, true>  $asked
     * @return list<array<string, mixed>>
     */
    private function projectOptions(int $workspaceId, int $userId, array $counts, array $asked): array
    {
        $projects = Client::acrossPeople()->where('workspace_id', $workspaceId)->where('user_id', $userId)
            ->orderBy('name')->get(['id', 'name', 'color', 'parent_id']);
        $children = [];
        foreach ($projects as $project) {
            $children[$project->parent_id !== null && $projects->contains('id', $project->parent_id) ? (string) $project->parent_id : ''][] = $project;
        }
        $options = [['value' => LibraryFilters::NO_PROJECT, 'count' => $counts[LibraryFilters::NO_PROJECT] ?? 0]];
        $walk = function (string $parent, int $depth) use (&$walk, &$options, $children, $counts): void {
            foreach ($children[$parent] ?? [] as $project) {
                $options[] = ['value' => (string) $project->id, 'count' => $counts[(string) $project->id] ?? 0, 'name' => $project->name,
                    'color' => $project->color, 'parent_id' => $depth === 0 ? null : $parent];
                $walk((string) $project->id, $depth + 1);
            }
        };
        $walk('', 0);
        // A project the filters name that is gone (or not the person's) still shows, so its pill can be taken off.
        foreach (array_keys($asked) as $id) {
            if ($id !== LibraryFilters::NO_PROJECT && ! $projects->contains('id', $id)) {
                $options[] = ['value' => $id, 'count' => 0, 'name' => null, 'color' => null, 'parent_id' => null];
            }
        }

        return $options;
    }

    /**
     * The person's lists in their order (empty ones too), then a label carried without a list, then one only the
     * filters name; each with the name as spelt and its colour.
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $present
     * @return list<array<string, mixed>>
     */
    private function labelOptions(int $workspaceId, int $userId, array $counts, array $present): array
    {
        $lists = LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->orderBy('position')->orderBy('id')->get(['label_key', 'name', 'color']);
        $options = [];
        foreach ($lists as $list) {
            $options[$list->label_key] = ['value' => $list->label_key, 'count' => $counts[$list->label_key] ?? 0, 'name' => $list->name, 'color' => $list->color];
        }
        $spelt = $this->spellings($workspaceId, $userId);
        $rest = array_values(array_diff($present, array_keys($options)));
        usort($rest, fn (string $a, string $b): int => strcmp($a, $b));
        foreach ($rest as $key) {
            $options[$key] = ['value' => $key, 'count' => $counts[$key] ?? 0, 'name' => $spelt[$key] ?? $key, 'color' => null];
        }

        return array_values($options);
    }

    /**
     * How the person's recordings spell each label (the first one's spelling), by key.
     *
     * @return array<string, string>
     */
    private function spellings(int $workspaceId, int $userId): array
    {
        $spelt = [];
        foreach (DB::table('session_labels')->whereIn('capture_session_id', self::personSessions($workspaceId, $userId))->orderBy('id')->pluck('label') as $label) {
            $spelt[LabelLists::key((string) $label)] ??= (string) $label;
        }

        return $spelt;
    }

    /**
     * Whether the row passes every section: true, else the first section it fails, or false when it fails two or more.
     *
     * @param  Row  $row
     * @param  Filters  $filters
     * @param  array<string, array{0: int|null, 1: int|null}>  $ranges
     */
    private static function passes(array $row, array $filters, array $ranges): string|bool
    {
        $failed = true;
        foreach ($filters as $section => $filter) {
            $hit = $section === 'date'
                ? self::within($row['started'], $ranges['date'])
                : array_intersect(self::valuesOf($row, $section), $filter['values']) !== [];
            if ($hit === ($filter['op'] === 'is')) {
                continue;
            }
            if ($failed !== true) {
                return false;
            }
            $failed = $section;
        }

        return $failed;
    }

    /**
     * @param  Row  $row
     * @return list<string>
     */
    private static function valuesOf(array $row, string $section): array
    {
        return match ($section) {
            'source', 'device', 'language', 'status' => [$row[$section]],
            'project' => $row['projects'],
            'labels' => $row['labels'],
            'marked' => $row['marked'],
            'bookmarks' => $row['bookmarks'],
            default => [],
        };
    }

    /** @param array{0: int|null, 1: int|null} $range */
    private static function within(int $instant, array $range): bool
    {
        return ($range[0] === null || $instant >= $range[0]) && ($range[1] === null || $instant < $range[1]);
    }

    /**
     * @param  Filters  $filters
     * @return array<string, array{0: int|null, 1: int|null}>
     */
    private static function ranges(array $filters, string $zone): array
    {
        return isset($filters['date']) ? ['date' => LibraryFilters::dateRange($filters['date'], $zone)] : [];
    }

    /**
     * The person's recordings that need them, by id, with the reason.
     *
     * @return array<string, string>
     */
    private function needsYouOf(int $workspaceId, int $userId): array
    {
        $now = Carbon::now()->getTimestamp();
        $found = [];
        foreach ($this->rows($workspaceId, $userId, $this->version($workspaceId, $userId)) as $row) {
            $reason = LibraryFilters::needsYou($row['session_status'], $row['status'], $row['ended'], $row['started'], $now);
            if ($reason !== null) {
                $found[$row['id']] = $reason;
            }
        }

        return $found;
    }

    /**
     * The rows the search finds (every row without one).
     *
     * @return list<Row>
     */
    private function searched(int $workspaceId, int $userId, ?string $query, string $version): array
    {
        $rows = $this->rows($workspaceId, $userId, $version);
        if ($query === null) {
            return $rows;
        }
        $key = 'library-facets:search:'.$workspaceId.':'.$userId.':'.sha1($version.'|'.$query);
        $ids = $this->memo[$key] ??= self::kept($version, $key, self::ROWS_TTL, fn (): array => $this->search->matching(
            CaptureSession::acrossPeople()->where('capture_sessions.workspace_id', $workspaceId)->where('capture_sessions.user_id', $userId), $query,
        )->pluck('capture_sessions.id')->map(fn ($id): string => (string) $id)->all());
        $found = array_flip($ids);

        return array_values(array_filter($rows, fn (array $row): bool => isset($found[$row['id']])));
    }

    /**
     * The person's library version, read once a request: the stamp listen() renews, and what the recordings, projects
     * and opens say of themselves (a change made without a model event still changes how many or the newest change).
     */
    private function version(int $workspaceId, int $userId): string
    {
        $key = self::versionKey($workspaceId, $userId);
        $known = request()->attributes->get($key);
        if (is_string($known)) {
            return $known;
        }
        $sessions = DB::table('capture_sessions')->where('workspace_id', $workspaceId)->where('user_id', $userId)
            ->selectRaw('COUNT(*) AS n, COUNT(deleted_at) AS gone, MAX(updated_at) AS changed')->first();
        $projects = DB::table('clients')->where('workspace_id', $workspaceId)->where('user_id', $userId)
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS changed')->first();
        $opens = DB::table('session_opens')->where('user_id', $userId)->whereIn('capture_session_id', self::personSessions($workspaceId, $userId))->count();
        // Changed in this request and not renewed yet: a version of this process alone, new at every change, never kept.
        $pending = self::pending($workspaceId, $userId);
        $version = sha1((string) json_encode([Cache::get(self::stampKey($workspaceId, $userId)), $sessions, $projects, $opens, $pending > 0 ? [getmypid(), spl_object_id($this), $pending] : null]));
        if ($pending > 0) {
            $version = 'local:'.$version;
        }
        request()->attributes->set($key, $version);

        return $version;
    }

    /**
     * The shared cache's answer for [$key], made by [$make] when missing; a version of this request alone (a change not
     * renewed yet) is never kept there.
     *
     * @template T
     *
     * @param  Closure(): T  $make
     * @return T
     */
    private static function kept(string $version, string $key, int $ttl, Closure $make): mixed
    {
        return str_starts_with($version, 'local:') ? $make() : Cache::remember($key, $ttl, $make);
    }

    /**
     * The compact row of every recording of the person (Trash left out, waiting calls in), in the order of the list.
     *
     * @return list<Row>
     */
    private function rows(int $workspaceId, int $userId, string $version): array
    {
        $key = 'library-facets:rows:'.$workspaceId.':'.$userId.':'.$version;

        /** @var list<Row> */
        return $this->memo[$key] ??= self::kept($version, $key, self::ROWS_TTL, fn (): array => $this->readRows($workspaceId, $userId));
    }

    /** @return list<Row> */
    private function readRows(int $workspaceId, int $userId): array
    {
        $mine = self::personSessions($workspaceId, $userId);
        $finals = [];
        foreach (DB::table('transcripts')->whereIn('capture_session_id', $mine)->where('kind', TranscriptKind::Final->value)
            ->orderBy('created_at')->orderBy('id')->get(['capture_session_id', 'status', 'language']) as $t) {
            $finals[(string) $t->capture_session_id] = [(string) $t->status, $t->language === null ? null : (string) $t->language];
        }
        $failedUploads = array_flip(DB::table('recordings')->whereIn('capture_session_id', $mine)->where('upload_status', UploadStatus::Failed->value)
            ->distinct()->pluck('capture_session_id')->map(fn ($id): string => (string) $id)->all());
        $labels = [];
        foreach (DB::table('session_labels')->whereIn('capture_session_id', $mine)->orderBy('id')->get(['capture_session_id', 'label']) as $l) {
            $key = LabelLists::key((string) $l->label);
            if (! in_array($key, $labels[(string) $l->capture_session_id] ?? [], true)) {
                $labels[(string) $l->capture_session_id][] = $key;
            }
        }
        $marks = [];
        foreach (DB::table('session_markers')->whereIn('capture_session_id', $mine)->orderBy('at_ms')->orderBy('id')->get(['capture_session_id', 'tag']) as $m) {
            $marks[(string) $m->capture_session_id][] = $m->tag === null ? null : (string) $m->tag;
        }
        $opened = array_flip(DB::table('session_opens')->where('user_id', $userId)->whereIn('capture_session_id', $mine)
            ->pluck('capture_session_id')->map(fn ($id): string => (string) $id)->all());
        $parents = Client::acrossPeople()->where('workspace_id', $workspaceId)->where('user_id', $userId)->pluck('parent_id', 'id')
            ->map(fn ($p): ?string => $p === null ? null : (string) $p)->all();

        $rows = [];
        foreach (DB::table('capture_sessions')->where('workspace_id', $workspaceId)->where('user_id', $userId)->whereNull('deleted_at')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->get(['id', 'started_at', 'ended_at', 'status', 'source_app', 'source_device', 'origin', 'client_id', 'language', 'pinned', 'important', 'keep_forever']) as $s) {
            $id = (string) $s->id;
            [$finalStatus, $finalLanguage] = $finals[$id] ?? [null, null];
            $marked = array_keys(array_filter(['pinned' => (bool) $s->pinned, 'important' => (bool) $s->important, 'keep_forever' => (bool) $s->keep_forever]));
            $rows[] = [
                'id' => $id,
                'started' => self::instant((string) $s->started_at),
                'ended' => $s->ended_at === null ? null : self::instant((string) $s->ended_at),
                'session_status' => (string) $s->status,
                'source' => LibraryFilters::source((string) $s->source_app, $s->origin === null ? null : (string) $s->origin),
                'device' => (string) $s->source_device,
                'projects' => $s->client_id === null ? [LibraryFilters::NO_PROJECT] : self::withAncestors((string) $s->client_id, $parents),
                'language' => LibraryFilters::language($finalLanguage, $s->language === null ? null : (string) $s->language),
                'status' => LibraryFilters::status((string) $s->status, isset($failedUploads[$id]), $finalStatus),
                'labels' => $labels[$id] ?? [],
                'marked' => $marked,
                'bookmarks' => LibraryFilters::bookmarks($marks[$id] ?? []),
                'opened' => isset($opened[$id]),
            ];
        }

        return $rows;
    }

    /**
     * A project and every folder above it: a recording in a subfolder is in each folder above it too.
     *
     * @param  array<string, string|null>  $parents
     * @return list<string>
     */
    private static function withAncestors(string $id, array $parents): array
    {
        $chain = [$id];
        $at = $parents[$id] ?? null;
        while ($at !== null && ! in_array($at, $chain, true) && count($chain) < 16) {
            $chain[] = $at;
            $at = $parents[$at] ?? null;
        }

        return $chain;
    }

    /** A stored timestamp (UTC) as Unix seconds (read for every row: no Carbon on the way). */
    private static function instant(string $stored): int
    {
        $seconds = strtotime($stored.(preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $stored) === 1 ? '' : ' UTC'));

        return $seconds === false ? Carbon::parse($stored, 'UTC')->getTimestamp() : $seconds;
    }

    /** The ids of the person's recordings in the workspace, as a subquery. */
    private static function personSessions(int $workspaceId, int $userId): \Illuminate\Database\Query\Builder
    {
        return DB::table('capture_sessions')->select('id')->where('workspace_id', $workspaceId)->where('user_id', $userId);
    }

    /**
     * The person's recordings the list may show (waiting calls only when asked), to send the shorter of the ids kept
     * and the ids left out.
     *
     * @return list<string>
     */
    private function liveIds(int $workspaceId, int $userId, bool $withWaiting = false): array
    {
        $ids = [];
        foreach ($this->rows($workspaceId, $userId, $this->version($workspaceId, $userId)) as $row) {
            if ($withWaiting || $row['session_status'] !== SessionStatus::Recording->value) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  Builder<CaptureSession>  $builder
     * @param  list<string>  $keep
     * @param  list<string>  $all  every id [$keep] is taken from
     * @return Builder<CaptureSession>
     */
    private static function restrictTo(Builder $builder, array $keep, array $all, bool $withWaiting = false): Builder
    {
        $column = $builder->qualifyColumn('id');
        if ($keep === []) {
            return $builder->whereRaw('1 = 0');
        }
        if (count($keep) * 2 <= count($all)) {
            return $builder->whereIn($column, $keep);
        }
        // Most are kept: the query names the few left out instead (a database takes only so many values at once), and
        // keeps to what the ids were taken from (out of Trash, waiting calls only when they count).
        $out = array_values(array_diff($all, $keep));
        $builder->whereNull($builder->qualifyColumn('deleted_at'));
        if (! $withWaiting) {
            $builder->where($builder->qualifyColumn('status'), '!=', SessionStatus::Recording->value);
        }

        return $out === [] ? $builder : $builder->whereNotIn($column, $out);
    }
}
