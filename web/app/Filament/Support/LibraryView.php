<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Stt\LanguageChoice;
use Closure;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Group by and Sort by in the console's Library (since v1.5, Phase 1C, D1–D4): the toolbar menus' choices, the groups
 * (a header with the name and how many recordings it holds, collapsible, sticky, with a select box for the whole group)
 * and the order within them, remembered per person and per view (Profile's `console_preferences`).
 */
final class LibraryView
{
    /** @var array<string, string> */
    public const GROUPS = [
        'date' => 'Date',
        'app' => 'App / Type',
        'project' => 'Project',
        'language' => 'Language',
        'status' => 'Status',
        'device' => 'Device',
        'none' => 'None',
    ];

    /** @var array<string, string> */
    public const SORTS = [
        'newest' => 'Newest',
        'oldest' => 'Oldest',
        'longest' => 'Longest',
        'shortest' => 'Shortest',
        'name' => 'Name A–Z',
        'opened' => 'Recently opened',
    ];

    public const DEFAULT_GROUP = 'date';

    public const DEFAULT_SORT = 'newest';

    /** @return list<Group> */
    public static function groups(): array
    {
        $zone = fn (): string => app(SettingsRepository::class)->displayTimezone();
        $day = fn (CaptureSession $r): string => $r->started_at->copy()->setTimezone($zone())->format('Y-m-d');

        return [
            self::group('date', $day, function (CaptureSession $r) use ($zone): string {
                $at = $r->started_at->copy()->setTimezone($zone());
                $now = Carbon::now($zone());

                return match (true) {
                    $at->isSameDay($now) => 'Today',
                    $at->isSameDay($now->copy()->subDay()) => 'Yesterday',
                    $at->year === $now->year => $at->format('l, M j'),
                    default => $at->format('l, M j, Y'),
                };
            }, function (Builder $q, string $key) use ($zone): Builder {
                $start = Carbon::parse($key, $zone())->startOfDay()->utc();

                return $q->where($q->qualifyColumn('started_at'), '>=', $start)->where($q->qualifyColumn('started_at'), '<', $start->copy()->addDay());
            }, fn (Builder $q, string $direction): Builder => $q->orderBy($q->qualifyColumn('started_at'), $direction)),
            self::group('app', fn (CaptureSession $r): string => $r->source_app->value, fn (CaptureSession $r): string => (string) $r->source_app->getLabel(),
                fn (Builder $q, string $key): Builder => $q->where($q->qualifyColumn('source_app'), $key),
                fn (Builder $q, string $direction): Builder => $q->orderBy($q->qualifyColumn('source_app'), $direction)),
            self::group('project', fn (CaptureSession $r): string => $r->client_id ?? 'none', fn (CaptureSession $r): string => $r->client->name ?? 'Inbox',
                fn (Builder $q, string $key): Builder => $key === 'none' ? $q->whereNull($q->qualifyColumn('client_id')) : $q->where($q->qualifyColumn('client_id'), $key),
                fn (Builder $q, string $direction): Builder => $q->orderBy(Client::query()->select('name')->whereColumn('clients.id', 'capture_sessions.client_id')->limit(1), $direction)),
            self::group('language', fn (CaptureSession $r): string => LanguageChoice::normalize($r->language) ?? 'auto',
                fn (CaptureSession $r): string => LanguageChoice::normalize($r->language) === null ? 'Automatic' : LanguageChoice::label($r->language),
                fn (Builder $q, string $key): Builder => $key === 'auto'
                    ? $q->where(fn (Builder $w): Builder => $w->whereNull($q->qualifyColumn('language'))->orWhere($q->qualifyColumn('language'), '')->orWhere($q->qualifyColumn('language'), 'auto'))
                    : $q->where($q->qualifyColumn('language'), $key),
                fn (Builder $q, string $direction): Builder => $q->orderBy($q->qualifyColumn('language'), $direction)),
            self::group('status', fn (CaptureSession $r): string => $r->status->value, fn (CaptureSession $r): string => (string) $r->status->getLabel(),
                fn (Builder $q, string $key): Builder => $q->where($q->qualifyColumn('status'), $key),
                fn (Builder $q, string $direction): Builder => $q->orderBy($q->qualifyColumn('status'), $direction)),
            self::group('device', fn (CaptureSession $r): string => $r->device_id !== null ? (string) $r->device_id : 'none:'.$r->source_device->value,
                fn (CaptureSession $r): string => CaptureSessionsTable::deviceLabel($r),
                fn (Builder $q, string $key): Builder => str_starts_with($key, 'none:')
                    ? $q->whereNull($q->qualifyColumn('device_id'))->where($q->qualifyColumn('source_device'), substr($key, 5))
                    : $q->where($q->qualifyColumn('device_id'), $key),
                fn (Builder $q, string $direction): Builder => $q->orderBy(Device::query()->select('name')->whereColumn('devices.id', 'capture_sessions.device_id')->limit(1), $direction)
                    ->orderBy($q->qualifyColumn('source_device'), $direction)),
        ];
    }

    /**
     * One group: its key and title per recording, how to narrow the list to one key (for the count and the select
     * box) and how to order by it.
     *
     * @param  Closure(CaptureSession): string  $key
     * @param  Closure(CaptureSession): string  $title
     * @param  Closure(Builder<CaptureSession>, string): Builder<CaptureSession>  $scope
     * @param  Closure(Builder<CaptureSession>, string): Builder<CaptureSession>  $order
     */
    private static function group(string $id, Closure $key, Closure $title, Closure $scope, Closure $order): Group
    {
        return Group::make($id)
            ->label(self::GROUPS[$id])
            ->collapsible()
            ->getKeyFromRecordUsing(fn (CaptureSession $record): string => $key($record))
            ->getTitleFromRecordUsing(fn (CaptureSession $record): string => $title($record))
            // The count of the whole group (every page), as the select box selects it: one query for all the groups.
            ->getDescriptionFromRecordUsing(function (CaptureSession $record, HasTable $livewire) use ($id, $key): string {
                $count = count(self::buckets($id, $livewire)[$key($record)] ?? []);

                return $count === 1 ? '1 recording' : number_format($count).' recordings';
            })
            ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => $scope($query, (string) $key))
            ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $order($query, $direction));
    }

    /** The columns each group's key is made from. */
    private const COUNT_COLUMNS = [
        'date' => ['started_at'], 'app' => ['source_app'], 'project' => ['client_id'], 'language' => ['language'],
        'status' => ['status'], 'device' => ['device_id', 'source_device'],
    ];

    /** @var array<string, array<string, list<string>>> group => key => its recordings' ids, for the render under way */
    private static array $buckets = [];

    public static function forgetCounts(): void
    {
        self::$buckets = [];
    }

    /**
     * The ids of the filtered list's recordings per key of the group, read with one query for all the groups (their
     * counts and their select boxes; a day in the console's time zone is worked out here).
     *
     * @return array<string, list<string>>
     */
    public static function buckets(string $id, HasTable $livewire): array
    {
        if (isset(self::$buckets[$id])) {
            return self::$buckets[$id];
        }
        $key = self::keyOf($id);
        $query = $livewire->getFilteredTableQuery();
        $buckets = [];
        if ($query !== null && $key !== null) {
            $columns = array_map(fn (string $c): string => 'capture_sessions.'.$c, ['id', ...self::COUNT_COLUMNS[$id]]);
            foreach ((clone $query)->withoutEagerLoads()->reorder()->toBase()->select($columns)->get() as $row) {
                $values = (array) $row;
                $buckets[$key((new CaptureSession)->setRawAttributes($values))][] = (string) $values['id'];
            }
        }

        return self::$buckets[$id] = $buckets;
    }

    /** @return (Closure(CaptureSession): string)|null */
    private static function keyOf(string $id): ?Closure
    {
        foreach (self::groups() as $group) {
            if ($group->getId() === $id) {
                return fn (CaptureSession $record): string => (string) $group->getStringKey($record);
            }
        }

        return null;
    }

    /**
     * The order within the groups (or of the whole list without them).
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    public static function applySort(Builder $query, string $sort, ?int $userId): Builder
    {
        $started = $query->qualifyColumn('started_at');
        $length = $query->qualifyColumn('duration_ms');

        return match ($sort) {
            'oldest' => $query->orderBy($started),
            'longest' => $query->orderByRaw("CASE WHEN {$length} IS NULL THEN 1 ELSE 0 END")->orderByDesc($length)->orderByDesc($started),
            'shortest' => $query->orderByRaw("CASE WHEN {$length} IS NULL THEN 1 ELSE 0 END")->orderBy($length)->orderByDesc($started),
            'name' => $query->orderByRaw('LOWER(COALESCE(NULLIF('.$query->qualifyColumn('title').", ''), ".$query->qualifyColumn('caller_name').', '.$query->qualifyColumn('source_app').'))')->orderByDesc($started),
            'opened' => $query->orderByRaw('CASE WHEN (SELECT MAX(opened_at) FROM session_opens WHERE session_opens.capture_session_id = capture_sessions.id AND session_opens.user_id = ?) IS NULL THEN 1 ELSE 0 END', [(int) $userId])
                ->orderByRaw('(SELECT MAX(opened_at) FROM session_opens WHERE session_opens.capture_session_id = capture_sessions.id AND session_opens.user_id = ?) DESC', [(int) $userId])
                ->orderByDesc($started),
            default => $query->orderByDesc($started),
        };
    }

    /** The direction the groups go in: the newest day first unless the list is sorted oldest first; names A–Z. */
    public static function groupDirection(string $group, string $sort): string
    {
        return $group === 'date' && $sort !== 'oldest' ? 'desc' : 'asc';
    }

    /**
     * The person's choice for one view of the Library, or the defaults.
     *
     * @return array{group: string, sort: string}
     */
    public static function remembered(?User $user, string $view): array
    {
        $saved = is_array($user?->console_preferences) ? ($user->console_preferences['library'][$view] ?? null) : null;
        $group = is_array($saved) && is_string($saved['group'] ?? null) && isset(self::GROUPS[$saved['group']]) ? $saved['group'] : self::DEFAULT_GROUP;
        $sort = is_array($saved) && is_string($saved['sort'] ?? null) && isset(self::SORTS[$saved['sort']]) ? $saved['sort'] : self::DEFAULT_SORT;

        return ['group' => $group, 'sort' => $sort];
    }

    public static function remember(User $user, string $view, string $group, string $sort): void
    {
        $preferences = is_array($user->console_preferences) ? $user->console_preferences : [];
        $library = is_array($preferences['library'] ?? null) ? $preferences['library'] : [];
        $library[mb_substr($view, 0, 64)] = ['group' => $group, 'sort' => $sort];
        // A handful of views at most; the oldest choices go first.
        $preferences['library'] = array_slice($library, -40, null, true);
        $user->forceFill(['console_preferences' => $preferences])->save();
    }
}
