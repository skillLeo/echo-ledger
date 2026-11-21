<?php

declare(strict_types=1);

namespace App\Services\Titles;

use App\Enums\SourceApp;
use App\Models\CaptureSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * The order of "Title from the person" (docs/API.md, since 2.0.0-k; CaptureSession::displayTitle) in SQL, so search
 * and the name sort follow the title people see: a person's own title, then the person called (a name, then a
 * number), then the stored title, then the app. GenericTitles' file-name patterns are not in SQL (see exactNames()).
 */
final class TitleSql
{
    /**
     * The title shown is the person's own (CaptureSession::titleIsOwn).
     *
     * @param  Builder<CaptureSession>  $query
     * @return array{0: string, 1: list<string>}
     */
    public static function own(Builder $query): array
    {
        [$title, $auto, $byPerson] = [$query->qualifyColumn('title'), $query->qualifyColumn('title_auto'), $query->qualifyColumn('title_by_person')];
        $names = GenericTitles::exactNames();

        return ["(TRIM(COALESCE({$title}, '')) <> '' AND ({$byPerson} = 1 OR ({$auto} = 0 AND LOWER(TRIM({$title})) NOT IN (".self::marks($names).'))))', $names];
    }

    /**
     * The person called has a name (CaptureSession::personTitle's first step).
     *
     * @param  Builder<CaptureSession>  $query
     * @return array{0: string, 1: list<string>}
     */
    public static function named(Builder $query): array
    {
        $name = $query->qualifyColumn('caller_name');
        $nobody = GenericTitles::exactNobodies();

        return ['('.self::call($query)." AND TRIM(COALESCE({$name}, '')) <> '' AND LOWER(TRIM({$name})) NOT IN (".self::marks($nobody).'))', $nobody];
    }

    /**
     * The person called has a number (personTitle's second step, without the name).
     *
     * @param  Builder<CaptureSession>  $query
     */
    public static function numbered(Builder $query): string
    {
        return '('.self::call($query)." AND TRIM(COALESCE({$query->qualifyColumn('caller_number')}, '')) <> '')";
    }

    /**
     * Recordings whose shown title has [$pattern] (a LIKE pattern, lower case, `!` escapes): the own title, else the
     * person called, else the stored title.
     *
     * @param  Builder<CaptureSession>  $query
     */
    public static function whereShownLike(Builder $query, string $pattern): void
    {
        [$own, $ownBindings] = self::own($query);
        [$named, $namedBindings] = self::named($query);
        $numbered = self::numbered($query);
        [$title, $name, $number] = [$query->qualifyColumn('title'), $query->qualifyColumn('caller_name'), $query->qualifyColumn('caller_number')];

        $query->where(fn (Builder $q) => $q
            ->whereRaw("({$own} AND LOWER({$title}) LIKE ? ESCAPE '!')", [...$ownBindings, $pattern])
            ->orWhereRaw("(NOT {$own} AND {$named} AND LOWER({$name}) LIKE ? ESCAPE '!')", [...$ownBindings, ...$namedBindings, $pattern])
            ->orWhereRaw("(NOT {$own} AND NOT {$named} AND {$numbered} AND LOWER({$number}) LIKE ? ESCAPE '!')", [...$ownBindings, ...$namedBindings, $pattern])
            ->orWhereRaw("(NOT {$own} AND NOT {$named} AND NOT {$numbered} AND LOWER({$title}) LIKE ? ESCAPE '!')", [...$ownBindings, ...$namedBindings, $pattern]));
    }

    /**
     * Sorts by the shown title, A to Z ("Call with …" as English words, whatever the console's language).
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    public static function orderByShown(Builder $query): Builder
    {
        [$own, $ownBindings] = self::own($query);
        [$named, $namedBindings] = self::named($query);
        $numbered = self::numbered($query);
        [$title, $name, $number, $source, $app] = [$query->qualifyColumn('title'), $query->qualifyColumn('caller_name'),
            $query->qualifyColumn('caller_number'), $query->qualifyColumn('caller_source'), $query->qualifyColumn('source_app')];
        $callWith = fn (string $column): string => self::concat($query, "'call with '", "LOWER(TRIM({$column}))");

        return $query->orderByRaw("CASE WHEN {$own} THEN LOWER({$title})"
            ." WHEN {$named} THEN CASE WHEN {$source} IN ('window_title', 'calendar') THEN LOWER(TRIM({$name})) ELSE {$callWith($name)} END"
            ." WHEN {$numbered} THEN {$callWith($number)}"
            ." ELSE LOWER(COALESCE(NULLIF({$title}, ''), {$app})) END", [...$ownBindings, ...$namedBindings]);
    }

    /** @param  Builder<CaptureSession>  $query */
    private static function call(Builder $query): string
    {
        return "{$query->qualifyColumn('source_app')} <> '".SourceApp::VoiceNote->value."'";
    }

    /** @param  Builder<CaptureSession>  $query */
    private static function concat(Builder $query, string $a, string $b): string
    {
        return in_array($query->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? "CONCAT({$a}, {$b})" : "({$a} || {$b})";
    }

    /** @param  list<string>  $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
