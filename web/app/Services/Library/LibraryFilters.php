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

    /** "Needs you": a recording stuck uploading or being transcribed this long after it ended. */
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
     * The Language of a recording: the language its transcript was written in, else the one chosen for it, else
     * `unknown`. Old and engine spellings read as the offered codes ("en+ur" and Hindi are Urdu, "ar-EG" Arabic).
     */
    public static function language(?string $finalLanguage, ?string $sessionLanguage): string
    {
        return LanguageChoice::normalize($finalLanguage) ?? LanguageChoice::normalize($sessionLanguage) ?? self::UNKNOWN_LANGUAGE;
    }

    /**
     * What a library item carries since 2.0.0-p (`facets`): the recording's Source, Status and Language, so the apps
     * count with the server's words for them.
     *
     * @return array{source: string, status: string, language: string}
     */
    public static function itemFacets(CaptureSession $session, bool $uploadFailed, ?string $finalStatus, ?string $finalLanguage): array
    {
        return [
            'source' => self::source($session->source_app->value, $session->origin),
            'status' => self::status($session->status->value, $uploadFailed, $finalStatus),
            'language' => self::language($finalLanguage, $session->language),
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
     * failed) or `stuck` (Status processing, and it ended more than STUCK_HOURS ago).
     */
    public static function needsYou(string $sessionStatus, string $status, ?int $endedAt, int $startedAt, int $now): ?string
    {
        if ($sessionStatus === SessionStatus::Recording->value) {
            return 'waiting';
        }
        if ($status === 'failed') {
            return 'failed';
        }

        return $status === 'processing' && ($endedAt ?? $startedAt) <= $now - self::STUCK_HOURS * 3600 ? 'stuck' : null;
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
