<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;

/**
 * The new frame's rules for the console's wide tables (W-201): Share links, My links, Devices and Request links fit the
 * page at 1024 px. Used only inside ConsoleV2::shell() branches; classic keeps its own columns and buttons.
 */
final class V2Tables
{
    /**
     * One date rule: "24 Nov 2026" in the console's language and, when the hour matters (a link can last an hour), the
     * time on a second line, readable without hovering.
     */
    public static function date(TextColumn $column, string $timezone, bool $withTime = true): TextColumn
    {
        $at = fn (mixed $state): ?Carbon => $state === null || $state === ''
            ? null
            : Carbon::parse($state)->setTimezone($timezone)->locale(app()->getLocale());
        $column->formatStateUsing(fn (mixed $state): ?string => $at($state)?->translatedFormat('j M Y'));

        return $withTime
            ? $column->description(fn (mixed $state): ?string => $at($state)?->translatedFormat(Shell::timeFormat()))
            : $column;
    }

    /** A column that made the table too wide: hidden at first, and always under the table's Columns menu. */
    public static function inColumns(Column $column): Column
    {
        return $column->toggleable(isToggledHiddenByDefault: true);
    }

    /**
     * The row's actions as one ⋮ menu, under Filament's own "Actions" label (in the console's language), with each
     * action's label, colour, confirmation and order as classic has them.
     *
     * @param  list<Action>  $actions
     * @return list<ActionGroup>
     */
    public static function actions(array $actions): array
    {
        return [ActionGroup::make($actions)];
    }
}
