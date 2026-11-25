<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Services\LibraryOrganiser;
use App\Stt\LanguageChoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The one definition of the Library's filters (contract 2.0.0-p, docs/API.md "Library filters"): the sections, the
 * options of each, "is" and "is not", and the value each recording has in each section. The phone and the desktop
 * count from their synced library with the same rules; tests/Fixtures/library-facets.json holds the cases all three
 * check.
 *
 * A filter set is `{section: {"op": "is"|"is_not", "values": [...]}}` with the sections in SECTIONS (date adds `from`
 * and `to` for `custom`). Sections combine with AND, the values of one section with OR; "is not" keeps the recordings
 * that have none of the values.
 */
final class LibraryFilters
{
    /** The sections in the order the filter panel shows them. */
    public const SECTIONS = ['date', 'source', 'device', 'project', 'language', 'status', 'labels', 'marked', 'bookmarks'];

    public const OPS = ['is', 'is_not'];

    /** Date: one of these (Any time is no filter). Custom names `from` and/or `to` (YYYY-MM-DD, both days included). */
    public const DATES = ['today', 'last_7_days', 'this_month', 'custom'];

    /**
     * Source: the app a call was recorded from, a voice note, or a file that came in as a file (imported on a device,
     * uploaded on the console or sent through a request link, whatever app it names). WhatsApp Web is WhatsApp.
     */
    public const SOURCES = ['whatsapp', 'whatsapp_business', 'google_meet', 'zoom', 'microsoft_teams', 'phone_call', 'telegram', 'signal',
        'messenger', 'instagram', 'skype', 'slack', 'discord', 'voice_note', 'imported', 'other'];

    /** Device (was "Recorded on"): the library item's `source_device`. */
    public const DEVICES = ['phone', 'desktop', 'web'];

    /** Status: every recording has exactly one (see status()). */
    public const STATUSES = ['transcribed', 'not_transcribed', 'processing', 'failed'];

    /** Marked: the person's pin, flag and Keep forever. */
    public const MARKED = ['pinned', 'important', 'keep_forever'];

    /** Bookmarks: a recording with any bookmark (the other options are bookmark tags, in lower case). */
    public const ANY_BOOKMARK = 'any';

    /** Project: recordings in no project. */
    public const NO_PROJECT = 'none';

    /** Language: recordings whose language is not known (automatic, and nothing transcribed yet). */
    public const UNKNOWN_LANGUAGE = 'unknown';

    /** A section takes at most this many values. */
    public const MAX_VALUES = 50;

    /**
     * The Library's kinds (docs/API.md "The Library's kinds", since 2.0.0-q): every recording has exactly one, worked out
     * from its Source. These are the Sources of each named kind; every other Source (imported, other, and any Source
     * added later) is `other`.
     */
    public const KIND_SOURCES = [
        'call' => ['whatsapp', 'whatsapp_business', 'phone_call', 'telegram', 'signal', 'messenger', 'instagram', 'skype'],
        'meeting' => ['zoom', 'google_meet', 'microsoft_teams', 'slack', 'discord'],
        'voice_note' => ['voice_note'],
    ];

    /** Every kind, in the tabs' order (a library item's `facets.kind`, the facets' `kinds`). */
    public const KIND_NAMES = ['call', 'meeting', 'voice_note', 'other'];

    /**
     * The console's address words for the tabs (`kind=`), each the kind it names: plural, as they are live since 2.0.0-p
     * and saved links keep them (docs/API.md "The Library's kinds"). All has none.
     */
    public const KINDS = ['calls' => 'call', 'meetings' => 'meeting', 'voice_notes' => 'voice_note', 'other' => 'other'];

    /** "Needs you": a recording stuck uploading or being transcribed for more than this since its current attempt began. */
    public const STUCK_HOURS = 24;

    /**
     * Every language the Language section can hold: the offered codes, then Unknown.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return [...array_values(array_filter(array_keys(LanguageChoice::OFFERED), fn (string|int $code): bool => $code !== '')), self::UNKNOWN_LANGUAGE];
    }

    /**
     * The Source of a recording: `imported` for a file that came in as a file, else its app (WhatsApp Web is WhatsApp).
     */
    public static function source(string $sourceApp, ?string $origin): string
    {
        if (in_array($origin, LibraryOrganiser::FILE_ORIGINS, true)) {
            return 'imported';
        }

        return $sourceApp === SourceApp::WhatsAppWeb->value ? SourceApp::WhatsApp->value
            : (in_array($sourceApp, self::SOURCES, true) ? $sourceApp : SourceApp::Other->value);
    }

    /** A Source's kind (docs/API.md "The Library's kinds"): the named kind that lists it, else `other`. */
    public static function kind(string $source): string
    {
        foreach (self::KIND_SOURCES as $kind => $sources) {
            if (in_array($source, $sources, true)) {
                return $kind;
            }
        }

        return 'other';
    }

    /**
     * The Sources of a kind, for narrowing to its tab: a named kind's list, or for `other` every Source no named kind
     * lists. Null for a word that is no kind.
     *
     * @return list<string>|null
     */
    public static function kindSources(string $kind): ?array
    {
        if (isset(self::KIND_SOURCES[$kind])) {
            return self::KIND_SOURCES[$kind];
        }

        return $kind === 'other' ? array_values(array_filter(self::SOURCES, fn (string $source): bool => self::kind($source) === 'other')) : null;
    }

    /**
     * The Status of a recording, the first that holds: `failed` (the recording failed, an upload of it failed, or its
     * transcript failed), `transcribed` (its transcript is finished), `processing` (still uploading or being processed,
     * or its transcript is on its way), else `not_transcribed` (kept as audio only, or kept without a transcript).
     */
    public static function status(string $sessionStatus, bool $uploadFailed, ?string $finalStatus): string
    {
        $moving = [TranscriptStatus::Pending->value, TranscriptStatus::Submitted->value, TranscriptStatus::Processing->value, TranscriptStatus::AwaitingDevice->value];

        return match (true) {
            $sessionStatus === SessionStatus::Failed->value || $uploadFailed || $finalStatus === TranscriptStatus::Failed->value => 'failed',
            $finalStatus === TranscriptStatus::Completed->value => 'transcribed',
            in_array($sessionStatus, [SessionStatus::Recording->value, SessionStatus::Uploading->value, SessionStatus::Processing->value], true),
            in_array($finalStatus, $moving, true) => 'processing',
            default => 'not_transcribed',
        };
    }

    /**
     * A `recording` session counts as waiting for the person's choice without an `ended_at` once it started this many
     * hours ago (contract 2.0.0-z, lead decision 20): older apps never send `ended_at` when the call ends, and no call runs
     * this long, so it is over and waiting (or forgotten), never still being recorded.
     */
    public const WAITING_FALLBACK_HOURS = 4;

    /**
     * Whether a recording waits for the person's choice after a call (contract 2.0.0-z): its session is still `recording`
     * and the call is over, `ended_at` set (PATCH /api/sessions/{id}) or started over WAITING_FALLBACK_HOURS ago. A
     * call still being recorded (live captions, an open meeting) is not waiting. Times are Unix seconds.
     */
    public static function isWaiting(string $sessionStatus, ?int $endedAt, int $startedAt, int $now): bool
    {
        return $sessionStatus === SessionStatus::Recording->value
            && ($endedAt !== null || $startedAt < $now - self::WAITING_FALLBACK_HOURS * 3600);
    }

    /**
     * isWaiting() in SQL, for the `waiting` view, its count and the badge.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereWaiting(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), SessionStatus::Recording->value)
            ->where(fn ($q) => $q->whereNotNull($query->qualifyColumn('ended_at'))
                ->orWhere($query->qualifyColumn('started_at'), '<', now()->utc()->subHours(self::WAITING_FALLBACK_HOURS)));
    }

    /** Since 2.0.0-r: the glossary's states, `facets.state`, in the contract's order (docs/API.md BE-05). */
    public const STATE_NAMES = ['transcribed', 'audio_only', 'transcribing', 'waiting_to_upload', 'failed', 'waiting'];

    /**
     * The glossary state of a recording (docs/PLAN_V2_1_POLISH.md section 1, `facets.state` since 2.0.0-r), from its one
     * Status: `failed` ("Failed · Retry"), `waiting` (a call waiting for the person's choice after it, in Needs you),
     * `waiting_to_upload` (not uploaded yet), `transcribing` ("Transcribing…"), `audio_only` (audio kept, no transcript)
     * or `transcribed` (the row shows its words, no state). The API, the console's rows, its Audio only place and its
     * Status filter all read this, so they agree.
     */
    public static function state(string $status, string $sessionStatus, bool $waiting): string
    {
        return match ($status) {
            'failed' => 'failed',
            'transcribed' => 'transcribed',
            'not_transcribed' => 'audio_only',
            default => match ($sessionStatus) {
                // Since 2.0.0-z ([$waiting], isWaiting): a call still being recorded is not waiting yet, not uploaded yet.
                SessionStatus::Recording->value => $waiting ? 'waiting' : 'waiting_to_upload',
                SessionStatus::Uploading->value => 'waiting_to_upload',
                default => 'transcribing',
            },
        };
    }

    /**
     * The Language of a recording: the language its transcript was written in, else the one chosen for it, else
     * `unknown`. Old and engine spellings read as the offered codes ("en+ur" and Hindi are Urdu, "ar-EG" Arabic).
     */
    public static function language(?string $finalLanguage, ?string $sessionLanguage): string
    {
        return LanguageChoice::normalize($finalLanguage) ?? LanguageChoice::normalize($sessionLanguage) ?? self::UNKNOWN_LANGUAGE;
    }

    /**
     * What a library item carries since 2.0.0-p (`facets`): the recording's Source, Status and Language, so the apps
     * count with the server's words for them, since 2.0.0-q its kind and since 2.0.0-r its state.
     *
     * @return array{source: string, status: string, language: string, kind: string, state: string}
     */
    public static function itemFacets(CaptureSession $session, bool $uploadFailed, ?string $finalStatus, ?string $finalLanguage): array
    {
        $source = self::source($session->source_app->value, $session->origin);
        $status = self::status($session->status->value, $uploadFailed, $finalStatus);

        return [
            'source' => $source,
            'status' => $status,
            'language' => self::language($finalLanguage, $session->language),
            // Since 2.0.0-q: the recording's one kind (docs/API.md "The Library's kinds").
            'kind' => self::kind($source),
            // Since 2.0.0-r: its one state, the glossary's (docs/API.md "The glossary's states").
            // Since 2.0.0-z: `waiting` only once the call is over (isWaiting).
            'state' => self::state($status, $session->status->value, self::isWaiting($session->status->value,
                $session->ended_at?->getTimestamp(), $session->started_at->getTimestamp(), now()->getTimestamp())),
        ];
    }

    /**
     * The Bookmarks values of a recording: `any` when it has a bookmark, then each tag its bookmarks carry, in lower
     * case (a library item's `marker_count` and `tags`).
     *
     * @param  iterable<string|null>  $tags  the tag of each bookmark (null for one without)
     * @return list<string>
     */
    public static function bookmarks(iterable $tags): array
    {
        $values = [];
        foreach ($tags as $tag) {
            $values = $values === [] ? [self::ANY_BOOKMARK] : $values;
            $clean = is_string($tag) ? LibraryOrganiser::cleanLabel($tag) : null;
            if ($clean !== null && ! in_array(mb_strtolower($clean), $values, true)) {
                $values[] = mb_strtolower($clean);
            }
        }

        return $values;
    }

    /**
     * "Needs you" for one recording, or null: `waiting` (a call still waiting for Keep or Discard), `failed` (Status
     * failed) or `stuck` (Status processing for more than STUCK_HOURS since [$processingSince], the start of the current
     * upload or transcript attempt, so a retry starts the clock again; only when none is stored, since it ended, else
     * since it started).
     */
    public static function needsYou(string $sessionStatus, string $status, ?int $endedAt, int $startedAt, int $now, ?int $processingSince = null): ?string
    {
        if ($sessionStatus === SessionStatus::Recording->value) {
            // Since 2.0.0-z: only once the call is over; a call still being recorded needs nothing yet.
            return self::isWaiting($sessionStatus, $endedAt, $startedAt, $now) ? 'waiting' : null;
        }
        if ($status === 'failed') {
            return 'failed';
        }

        return $status === 'processing' && ($processingSince ?? $endedAt ?? $startedAt) < $now - self::STUCK_HOURS * 3600 ? 'stuck' : null;
    }

    /**
     * The start and end (exclusive) of a date filter as Unix seconds, in [$zone]; either may be null (open).
     *
     * @param  array{values: list<string>, from?: string|null, to?: string|null}  $date
     * @return array{0: int|null, 1: int|null}
     */
    public static function dateRange(array $date, string $zone, ?Carbon $now = null): array
    {
        $today = ($now ?? Carbon::now())->copy()->setTimezone($zone)->startOfDay();

        return match ($date['values'][0] ?? null) {
            'today' => [$today->getTimestamp(), $today->copy()->addDay()->getTimestamp()],
            'last_7_days' => [$today->copy()->subDays(6)->getTimestamp(), $today->copy()->addDay()->getTimestamp()],
            'this_month' => [$today->copy()->startOfMonth()->getTimestamp(), $today->copy()->startOfMonth()->addMonth()->getTimestamp()],
            'custom' => [
                isset($date['from']) ? Carbon::parse($date['from'], $zone)->startOfDay()->getTimestamp() : null,
                isset($date['to']) ? Carbon::parse($date['to'], $zone)->startOfDay()->addDay()->getTimestamp() : null,
            ],
            default => [null, null],
        };
    }

    /**
     * A filter set as kept and compared: known sections in SECTIONS order, values de-duplicated in the order given
     * (labels as their keys, in lower case), and empty sections or Any time dropped. A JSON string is read first.
     * Anything else is `422 validation_failed` with the field named.
     *
     * @return array<string, array{op: string, values: list<string>, from?: string, to?: string}>
     */
    public static function normalize(mixed $input, string $field = 'filters'): array
    {
        if ($input === null || $input === '' || $input === []) {
            return [];
        }
        if (is_string($input)) {
            try {
                $input = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                self::fail($field, 'The filters must be a JSON object.');
            }
        }
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            self::fail($field, 'The filters must be an object of sections.');
        }
        foreach (array_keys($input) as $section) {
            if (! in_array($section, self::SECTIONS, true)) {
                self::fail("{$field}.{$section}", 'This filter is not known.');
            }
        }
        $out = [];
        foreach (self::SECTIONS as $section) {
            if (! array_key_exists($section, $input) || $input[$section] === null) {
                continue;
            }
            $clean = self::section($section, $input[$section], "{$field}.{$section}");
            if ($clean !== null) {
                $out[$section] = $clean;
            }
        }

        return $out;
    }

    /**
     * A filter set kept earlier (a saved view), read as forgivingly as the apps read one: a section or option no longer
     * offered is left out, and a section that no longer reads (or is left with no value) goes, never an error. Writes
     * stay strict (normalize()).
     *
     * @return array<string, array{op: string, values: list<string>, from?: string, to?: string}>
     */
    public static function read(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (! is_array($stored)) {
            return [];
        }
        $out = [];
        foreach (self::SECTIONS as $section) {
            $raw = $stored[$section] ?? null;
            if (! is_array($raw)) {
                continue;
            }
            $values = array_values(array_filter(is_array($raw['values'] ?? null) ? $raw['values'] : [],
                fn (mixed $value): bool => is_string($value) && self::value($section, $value) !== null));
            try {
                $clean = self::section($section, [...$raw, 'values' => array_slice($values, 0, self::MAX_VALUES)], 'filters.'.$section);
            } catch (ApiError) {
                continue;
            }
            if ($clean !== null) {
                $out[$section] = $clean;
            }
        }

        return $out;
    }

    /** @return array{op: string, values: list<string>, from?: string, to?: string}|null */
    private static function section(string $section, mixed $raw, string $field): ?array
    {
        if (! is_array($raw)) {
            self::fail($field, 'A filter is {"op": "is" | "is_not", "values": [...]}.');
        }
        $op = $raw['op'] ?? 'is';
        if (! is_string($op) || ! in_array($op, self::OPS, true)) {
            self::fail("{$field}.op", 'The op must be is or is_not.');
        }
        $given = $raw['values'] ?? [];
        if (! is_array($given) || ! array_is_list($given)) {
            self::fail("{$field}.values", 'The values must be a list.');
        }
        if (count($given) > self::MAX_VALUES) {
            self::fail("{$field}.values", 'At most '.self::MAX_VALUES.' values.');
        }
        $values = [];
        foreach ($given as $value) {
            if (! is_string($value)) {
                self::fail("{$field}.values", 'Each value is a string.');
            }
            $clean = self::value($section, $value);
            if ($clean === null) {
                self::fail("{$field}.values", "\"{$value}\" is not one of this filter's options.");
            }
            if (! in_array($clean, $values, true)) {
                $values[] = $clean;
            }
        }
        if ($section === 'date') {
            return self::date($op, $values, $raw, $field);
        }

        return $values === [] ? null : ['op' => $op, 'values' => $values];
    }

    /**
     * @param  list<string>  $values
     * @param  array<mixed>  $raw
     * @return array{op: string, values: list<string>, from?: string, to?: string}|null
     */
    private static function date(string $op, array $values, array $raw, string $field): ?array
    {
        $values = array_values(array_diff($values, ['any']));
        if ($values === []) {
            return null;
        }
        if (count($values) > 1) {
            self::fail("{$field}.values", 'Date takes one value.');
        }
        if ($values[0] !== 'custom') {
            return ['op' => $op, 'values' => $values];
        }
        $range = [];
        foreach (['from', 'to'] as $end) {
            $day = $raw[$end] ?? null;
            if ($day === null || $day === '') {
                continue;
            }
            if (! is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
                self::fail("{$field}.{$end}", 'The day must be YYYY-MM-DD.');
            }
            $range[$end] = $day;
        }
        if ($range === []) {
            self::fail("{$field}.from", 'A custom date needs from, to or both.');
        }
        if (isset($range['from'], $range['to']) && $range['from'] > $range['to']) {
            self::fail("{$field}.to", 'The end comes before the start.');
        }

        return ['op' => $op, 'values' => ['custom'], ...$range];
    }

    /** A value as kept, or null when the section has no such option. */
    private static function value(string $section, string $value): ?string
    {
        $value = trim($value);

        return match ($section) {
            'date' => in_array($value, [...self::DATES, 'any'], true) ? $value : null,
            'source' => in_array($value, self::SOURCES, true) ? $value : null,
            'device' => in_array($value, self::DEVICES, true) ? $value : null,
            // A project that is gone later matches nothing: a view may outlive it.
            'project' => $value === self::NO_PROJECT || preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1 ? $value : null,
            'language' => in_array($value, self::languages(), true) ? $value : null,
            'status' => in_array($value, self::STATUSES, true) ? $value : null,
            'labels' => ($label = LibraryOrganiser::cleanLabel($value)) !== null ? mb_strtolower($label) : null,
            'marked' => in_array($value, self::MARKED, true) ? $value : null,
            'bookmarks' => ($tag = LibraryOrganiser::cleanLabel($value)) !== null ? mb_strtolower($tag) : null,
            default => null,
        };
    }

    private static function fail(string $field, string $message): never
    {
        throw new ApiError(422, 'validation_failed', $message, ['errors' => [$field => [$message]]]);
    }
}
