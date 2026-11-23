<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Exceptions\ApiError;
use App\Filament\Support\V2RecordingRow;
use App\Models\LabelColor;
use App\Models\LabelList;
use App\Services\AuditLog;
use App\Services\Library\LibraryFacets;
use App\Services\LibraryOrganiser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A person's lists in one workspace (contract 2.0.0-m, docs/API.md "Lists"): the apps' row of All · Unread · Important
 * · the person's lists. A list is a label: renaming one relabels every recording of the person that carries it, deleting
 * one takes the label off them (the recordings stay), and labelling a recording makes the list when it is new. Labels
 * are compared ignoring capitals only (mb_strtolower), as label colours are since 2.0.0-i.
 */
final class LabelLists
{
    /** The apps' fixed chips (All, Unread, Important) in English and Urdu: no list takes one of these names. */
    public const RESERVED = ['all', 'unread', 'important', 'سب', 'نہ پڑھی گئی', 'اہم'];

    /** A list is a label: at most as long as one. */
    public const MAX_NAME = LibraryOrganiser::MAX_LABEL_CHARS;

    /**
     * The colours a list can have: the design system's eight label colours, in the apps' picker order.
     *
     * @return list<string>
     */
    public static function colours(): array
    {
        return array_values(V2RecordingRow::LABEL_COLOURS);
    }

    /** The key of a list (and of a label): the name in lower case. */
    public static function key(string $name): string
    {
        return mb_strtolower($name);
    }

    /**
     * The person's lists in their order, each with how many of their recordings (not in Trash) are in it. A label one
     * of their recordings carries without a list yet (written before 2.0.0-m by some other way) gets one first.
     *
     * @return list<array{key: string, name: string, color: string|null, position: int, count: int}>
     */
    public function index(int $workspaceId, int $userId): array
    {
        $this->ensure($workspaceId, $userId, $this->carried($workspaceId, $userId, true));
        $counts = $this->counts($workspaceId, $userId);

        return $this->rows($workspaceId, $userId)->map(fn (LabelList $list): array => self::payload($list, $counts[$list->label_key] ?? 0))->values()->all();
    }

    /** @return Collection<int, LabelList> */
    public function rows(int $workspaceId, int $userId): Collection
    {
        return LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Makes the lists for these labels that the person has not got yet, at the end of the row and without a colour
     * (the apps draw it in their neutral colour until one is chosen). Called whenever a recording is labelled. A label
     * spelt as a fixed chip ("Important", "Unread", written before 2.0.0-m) stays on its recordings but is never a list.
     *
     * @param  iterable<string>  $labels  labels as stored (LibraryOrganiser::cleanLabel)
     */
    public function ensure(int $workspaceId, int $userId, iterable $labels): void
    {
        $wanted = [];
        foreach ($labels as $label) {
            $key = self::key($label);
            if (! self::reserved($key)) {
                $wanted[$key] ??= $label;
            }
        }
        $person = fn () => LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId);
        // Most calls find every list there already: answered without a lock.
        if ($wanted === [] || array_diff_key($wanted, $person()->pluck('label_key')->flip()->all()) === []) {
            return;
        }
        DB::transaction(function () use ($workspaceId, $userId, $wanted, $person): void {
            // The person's lists, locked before the end of the row is read: two devices labelling at once queue here,
            // so each new list gets a place of its own.
            $have = $person()->lockForUpdate()->get(['label_key', 'position']);
            $existing = $have->pluck('label_key')->flip()->all();
            $next = $have->isEmpty() ? 0 : (int) $have->max('position') + 1;
            $rows = [];
            foreach (array_diff_key($wanted, $existing) as $key => $label) {
                $rows[] = ['workspace_id' => $workspaceId, 'user_id' => $userId, 'label_key' => (string) $key, 'name' => $label, 'color' => null,
                    'position' => $next++, 'created_at' => now(), 'updated_at' => now()];
            }
            if ($rows === []) {
                return;
            }
            // Two devices labelling at once: the unique index keeps one list, the other insert is skipped.
            LabelList::query()->insertOrIgnore($rows);
            // Written without model events: the Library's counts are told themselves (a new list is an option).
            LibraryFacets::forget($workspaceId, $userId);
            // With no list of the person's to lock yet (their first ones), two inserts can still take one place: the
            // row is put back in order, with no gaps.
            $this->renumber($workspaceId, $userId);
        });
    }

    /** True for a fixed chip's word (All, Unread, Important, in English or Urdu): never the name of a list. */
    public static function reserved(string $key): bool
    {
        return in_array($key, self::RESERVED, true);
    }

    /**
     * POST /api/lists: a new, empty list at the end of the row. Without a colour it takes the one fewest of the person's
     * lists have (the earliest of those in the picker's order).
     *
     * @return array{key: string, name: string, color: string|null, position: int, count: int}
     */
    public function create(int $workspaceId, int $userId, mixed $name, ?string $color): array
    {
        // A label of theirs without a list yet is a list all the same: its name is taken.
        $this->ensure($workspaceId, $userId, $this->carried($workspaceId, $userId, true));
        $name = $this->freeName($workspaceId, $userId, $name);
        $color = $color === null ? $this->leastUsedColour($workspaceId, $userId) : self::paletteColour($color);
        $position = LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->max('position');
        try {
            $list = LabelList::query()->create(['workspace_id' => $workspaceId, 'user_id' => $userId, 'label_key' => self::key($name), 'name' => $name,
                'color' => $color, 'position' => $position === null ? 0 : (int) $position + 1]);
        } catch (UniqueConstraintViolationException) {
            // Made by another device in the same moment.
            throw self::exists($name);
        }
        app(AuditLog::class)->record('list_created', null, null, "List made: {$name}");

        return self::payload($list, $this->counts($workspaceId, $userId)[$list->label_key] ?? 0);
    }

    /**
     * PATCH /api/lists/{key}: a new name, a new colour, or both. A new name is the label's new spelling on every
     * recording of the person that carries it, all in one transaction; a change of capitals alone is allowed.
     *
     * @return array{key: string, name: string, color: string|null, position: int, count: int}
     */
    public function update(int $workspaceId, int $userId, string $key, bool $renaming, mixed $name, bool $recolouring, ?string $color): array
    {
        $list = $this->find($workspaceId, $userId, $key);
        if ($renaming) {
            $this->ensure($workspaceId, $userId, $this->carried($workspaceId, $userId, true));
        }
        $newName = $renaming ? $this->freeName($workspaceId, $userId, $name, $list) : $list->name;
        $newColor = $recolouring ? self::paletteColour((string) $color) : $list->color;
        $renamed = $newName !== $list->name;
        if (! $renamed && $newColor === $list->color) {
            return self::payload($list, $this->counts($workspaceId, $userId)[$list->label_key] ?? 0);
        }
        $oldName = $list->name;
        try {
            DB::transaction(function () use ($workspaceId, $userId, $list, $newName, $newColor, $renamed): void {
                $labels = $this->labelsOf($workspaceId, $userId);
                $carriers = array_values(array_filter($labels, fn (object $l): bool => self::key((string) $l->label) === $list->label_key));
                if ($renamed) {
                    $newKey = self::key($newName);
                    // The recordings that already carry the new name (read with the carriers, in the same locked
                    // read): they keep one label. So does one that carries the old name twice in other capitals.
                    $twinned = [];
                    foreach ($labels as $l) {
                        if ($newKey !== $list->label_key && self::key((string) $l->label) === $newKey) {
                            $twinned[(string) $l->capture_session_id] = true;
                        }
                    }
                    $drop = $keep = [];
                    foreach ($carriers as $l) {
                        $session = (string) $l->capture_session_id;
                        if (isset($twinned[$session])) {
                            $drop[] = (int) $l->id;
                        } else {
                            $keep[] = (int) $l->id;
                            $twinned[$session] = true;
                        }
                    }
                    // A set of statements whatever the number of recordings: one delete, one update (a chunk a
                    // thousand, within every database's limit on bound values).
                    foreach (array_chunk($drop, 1000) as $ids) {
                        DB::table('session_labels')->whereIn('id', $ids)->delete();
                    }
                    foreach (array_chunk($keep, 1000) as $ids) {
                        DB::table('session_labels')->whereIn('id', $ids)->update(['label' => $newName]);
                    }
                    $list->label_key = $newKey;
                    $list->name = $newName;
                }
                $list->color = $newColor;
                $list->save();
                self::touch(array_map(fn (object $l): string => (string) $l->capture_session_id, $carriers));
            });
        } catch (UniqueConstraintViolationException) {
            // Another device gave a list this name in the same moment, or a recording carries a label the database
            // counts as the same ("Résumé" and "Resume" under MySQL's accent-blind collation): nothing changed.
            throw self::exists($this->clashing($workspaceId, $userId, $newName, $list->id));
        }
        app(LibraryOrganiser::class)->forgetColors($workspaceId, $userId);
        if ($renamed) {
            app(AuditLog::class)->record('list_renamed', null, null, "List renamed: {$oldName} → {$newName}");
        }

        return self::payload($list, $this->counts($workspaceId, $userId)[$list->label_key] ?? 0);
    }

    /**
     * DELETE /api/lists/{key}: the label comes off every recording of the person that carries it, in one transaction;
     * the recordings stay. Returns how many recordings were changed.
     *
     * @return array{key: string, changed: int}
     */
    public function delete(int $workspaceId, int $userId, string $key): array
    {
        $list = $this->find($workspaceId, $userId, $key);
        $changed = 0;
        DB::transaction(function () use ($workspaceId, $userId, $list, &$changed): void {
            $carriers = $this->carriers($workspaceId, $userId, $list->label_key);
            DB::table('session_labels')->whereIn('id', array_map(fn (object $l): int => (int) $l->id, $carriers))->delete();
            $sessions = array_values(array_unique(array_map(fn (object $l): string => (string) $l->capture_session_id, $carriers)));
            self::touch($sessions);
            $changed = count($sessions);
            $list->delete();
            // The row keeps no gaps.
            $this->renumber($workspaceId, $userId);
        });
        app(LibraryOrganiser::class)->forgetColors($workspaceId, $userId);
        app(AuditLog::class)->record('list_deleted', null, null, "List deleted: {$list->name}");

        return ['key' => $list->label_key, 'changed' => $changed];
    }

    /**
     * PUT /api/lists/order: the person's lists in a new order. `keys` names every list of theirs exactly once, else
     * 422 and nothing moves.
     *
     * @param  list<mixed>  $keys
     * @return list<array{key: string, name: string, color: string|null, position: int, count: int}>
     */
    public function order(int $workspaceId, int $userId, array $keys): array
    {
        $this->ensure($workspaceId, $userId, $this->carried($workspaceId, $userId, true));
        $wanted = array_map(fn (mixed $k): string => self::key(LibraryOrganiser::cleanLabel($k) ?? (is_string($k) ? $k : '')), $keys);
        $rows = $this->rows($workspaceId, $userId)->keyBy('label_key');
        $have = $rows->keys()->map(fn ($k): string => (string) $k)->sort()->values()->all();
        $given = $wanted;
        sort($given);
        if ($given !== $have) {
            throw new ApiError(422, 'validation_failed', 'Name each of your lists once.', ['errors' => ['keys' => ['Name each of your lists once.']]]);
        }
        DB::transaction(function () use ($wanted, $rows): void {
            foreach ($wanted as $position => $key) {
                $list = $rows[$key];
                if ($list->position !== $position) {
                    $list->forceFill(['position' => $position])->save();
                }
            }
        });

        return $this->index($workspaceId, $userId);
    }

    /**
     * PUT /api/labels/color (2.0.0-i), kept on the list since 2.0.0-m: the colour of a label one of the person's
     * recordings carries (any #rrggbb, or none).
     */
    public function setColour(int $workspaceId, int $userId, string $spelt, ?string $color): void
    {
        if (self::reserved(self::key($spelt))) {
            // A label spelt as a fixed chip has no list: its colour stays where 2.0.0-i kept it.
            $row = ['workspace_id' => $workspaceId, 'user_id' => $userId, 'label_key' => self::key($spelt)];
            $color === null
                ? LabelColor::query()->where($row)->delete()
                : LabelColor::query()->upsert([[...$row, 'color' => $color, 'created_at' => now(), 'updated_at' => now()]],
                    ['workspace_id', 'user_id', 'label_key'], ['color', 'updated_at']);

            return;
        }
        $this->ensure($workspaceId, $userId, [$spelt]);
        LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->where('label_key', self::key($spelt))
            ->update(['color' => $color, 'updated_at' => now()]);
        // Written without model events: the Labels section's colour is read again (LibraryFacets).
        LibraryFacets::forget($workspaceId, $userId);
    }

    /**
     * The person's label colours, key => "#rrggbb", for the labels that have one.
     *
     * @return array<string, string>
     */
    public function colors(int $workspaceId, int $userId): array
    {
        // One read: the lists' colours, and (a label spelt as a fixed chip has no list) the colour 2.0.0-i kept for one.
        $reserved = DB::table('label_colors')->where('workspace_id', $workspaceId)->where('user_id', $userId)->whereIn('label_key', self::RESERVED)
            ->select(['label_key', 'color', DB::raw('1 as fallback')]);
        $colors = [];
        foreach (DB::table('label_lists')->where('workspace_id', $workspaceId)->where('user_id', $userId)->whereNotNull('color')
            ->select(['label_key', 'color', DB::raw('0 as fallback')])->unionAll($reserved)->get() as $row) {
            $colors[(string) $row->label_key] ??= strtolower((string) $row->color);
        }

        return $colors;
    }

    /** The colour fewest of the person's lists have, the earliest in the picker's order on a tie. */
    public function leastUsedColour(int $workspaceId, int $userId): string
    {
        $used = array_fill_keys(self::colours(), 0);
        foreach (LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->whereNotNull('color')->pluck('color') as $c) {
            $c = strtolower((string) $c);
            if (isset($used[$c])) {
                $used[$c]++;
            }
        }
        $best = self::colours()[0];
        foreach ($used as $colour => $n) {
            if ($n < $used[$best]) {
                $best = $colour;
            }
        }

        return $best;
    }

    /** @return array{key: string, name: string, color: string|null, position: int, count: int} */
    private static function payload(LabelList $list, int $count): array
    {
        return ['key' => $list->label_key, 'name' => $list->name, 'color' => $list->color === null ? null : strtolower($list->color),
            'position' => $list->position, 'count' => $count];
    }

    private function find(int $workspaceId, int $userId, string $key): LabelList
    {
        $key = self::key(LibraryOrganiser::cleanLabel($key) ?? $key);
        $find = fn (): ?LabelList => LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->where('label_key', $key)->first();
        $list = $find();
        if ($list === null && in_array($key, array_map(self::key(...), $this->carried($workspaceId, $userId, true)), true)) {
            $this->ensure($workspaceId, $userId, $this->carried($workspaceId, $userId, true));
            $list = $find();
        }
        if (! $list instanceof LabelList) {
            throw new ApiError(404, 'list_not_found', 'List not found.');
        }

        return $list;
    }

    /** A name the person can give a list: cleaned as a label, not a fixed chip's word, not another list's name. */
    private function freeName(int $workspaceId, int $userId, mixed $name, ?LabelList $self = null): string
    {
        $raw = is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', '', $name))) : '';
        if ($raw === '') {
            throw new ApiError(422, 'validation_failed', 'The name is required.', ['errors' => ['name' => ['The name is required.']]]);
        }
        if (mb_strlen($raw) > self::MAX_NAME) {
            throw new ApiError(422, 'validation_failed', 'The name is at most '.self::MAX_NAME.' characters.', ['errors' => ['name' => ['The name is at most '.self::MAX_NAME.' characters.']]]);
        }
        if (self::reserved(self::key($raw))) {
            throw new ApiError(422, 'name_reserved', "{$raw} is one of the fixed lists. Choose another name.", ['errors' => ['name' => ["{$raw} is one of the fixed lists."]]]);
        }
        $other = LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->where('label_key', self::key($raw))
            ->when($self !== null, fn ($q) => $q->whereKeyNot($self->id))->first();
        if ($other instanceof LabelList) {
            throw self::exists($other->name);
        }

        return $raw;
    }

    private static function exists(string $name): ApiError
    {
        return new ApiError(409, 'list_exists', "You already have a list called {$name}.", ['name' => $name]);
    }

    private static function paletteColour(string $color): string
    {
        $color = strtolower($color);
        if (! in_array($color, self::colours(), true)) {
            throw new ApiError(422, 'validation_failed', 'Choose one of the eight list colours.', ['errors' => ['color' => ['Choose one of the eight list colours.']]]);
        }

        return $color;
    }

    /**
     * The person's labels on this key: every recording of theirs in the workspace, those in Trash too (so a recording
     * brought back has the list's name), compared in PHP as setLabelColor does.
     *
     * @return list<object{id: int, capture_session_id: string, label: string}>
     */
    private function carriers(int $workspaceId, int $userId, string $key): array
    {
        return array_values(array_filter($this->labelsOf($workspaceId, $userId), fn (object $l): bool => self::key((string) $l->label) === $key));
    }

    /**
     * Every label on the person's recordings in the workspace (Trash too), locked until the transaction ends.
     *
     * @return list<object{id: int, capture_session_id: string, label: string}>
     */
    private function labelsOf(int $workspaceId, int $userId): array
    {
        return DB::table('session_labels')->join('capture_sessions', 'capture_sessions.id', '=', 'session_labels.capture_session_id')
            ->where('capture_sessions.workspace_id', $workspaceId)->where('capture_sessions.user_id', $userId)
            ->orderBy('session_labels.id')->lockForUpdate()
            ->get(['session_labels.id', 'session_labels.capture_session_id', 'session_labels.label'])->values()->all();
    }

    /** The person's lists numbered from 0 in their order (position, then age), with no gaps and no place twice. */
    private function renumber(int $workspaceId, int $userId): void
    {
        foreach ($this->rows($workspaceId, $userId) as $i => $list) {
            if ($list->position !== $i) {
                $list->forceFill(['position' => $i])->save();
            }
        }
    }

    /**
     * The name of the person's list a rename ran into: the one whose name matches ignoring capitals and accents (as
     * the database's collation does), else the name asked for.
     */
    private function clashing(int $workspaceId, int $userId, string $name, int $self): string
    {
        $fold = fn (string $s): string => mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D)));
        $other = LabelList::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->whereKeyNot($self)->get(['name'])
            ->first(fn (LabelList $l): bool => $fold($l->name) === $fold($name));

        return $other instanceof LabelList ? $other->name : $name;
    }

    /**
     * The labels the person's recordings carry, each spelt as its first recording does.
     *
     * @return list<string>
     */
    private function carried(int $workspaceId, int $userId, bool $withTrashed): array
    {
        $out = [];
        DB::table('session_labels')->join('capture_sessions', 'capture_sessions.id', '=', 'session_labels.capture_session_id')
            ->where('capture_sessions.workspace_id', $workspaceId)->where('capture_sessions.user_id', $userId)
            ->when(! $withTrashed, fn ($q) => $q->whereNull('capture_sessions.deleted_at'))
            ->selectRaw('session_labels.label, MIN(session_labels.id) as first')->groupBy('session_labels.label')->orderBy('first')->get()
            ->each(function (object $l) use (&$out): void {
                $out[self::key((string) $l->label)] ??= (string) $l->label;
            });

        return array_values($out);
    }

    /**
     * How many of the person's recordings (not in Trash) carry each label, key => count.
     *
     * @return array<string, int>
     */
    private function counts(int $workspaceId, int $userId): array
    {
        $counts = [];
        DB::table('session_labels')->join('capture_sessions', 'capture_sessions.id', '=', 'session_labels.capture_session_id')
            ->where('capture_sessions.workspace_id', $workspaceId)->where('capture_sessions.user_id', $userId)->whereNull('capture_sessions.deleted_at')
            ->selectRaw('session_labels.label, COUNT(*) as aggregate')->groupBy('session_labels.label')->get()
            ->each(function (object $l) use (&$counts): void {
                $key = self::key((string) $l->label);
                $counts[$key] = ($counts[$key] ?? 0) + (int) $l->aggregate;
            });

        return $counts;
    }

    /** @param list<string> $ids recordings whose labels changed: a new updated_at, so the other devices sync them */
    private static function touch(array $ids): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            DB::table('capture_sessions')->whereIn('id', $chunk)->update(['updated_at' => now()]);
        }
    }
}
