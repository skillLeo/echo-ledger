<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Dates in the console's language. English keeps the formats the console has always written ("Nov 17",
 * "Nov 17, 2026", "November 2026"); Urdu reads day, month name, year ("17 نومبر 2026") with the digits 0–9
 * (docs/v2/design-review/D4-urdu-arabic-typography.md §5). The language is the app's locale unless one is given.
 */
final class ConsoleDates
{
    /** Arabic-Indic and Eastern Arabic-Indic digits, written as 0–9. */
    private const LATIN_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** Whether dates are written in Urdu. */
    public static function urdu(?string $locale = null): bool
    {
        return ($locale ?? app()->getLocale()) === 'ur';
    }

    /**
     * One date, in English with [$english] exactly as PHP's format() writes it, in Urdu with [$urdu] in Carbon's Urdu
     * month and day names.
     */
    public static function format(CarbonInterface $at, string $english, string $urdu, ?string $locale = null): string
    {
        if (! self::urdu($locale)) {
            return $at->format($english);
        }

        return strtr($at->copy()->locale('ur')->translatedFormat($urdu), self::LATIN_DIGITS);
    }

    /** "Nov 17" · "17 نومبر" */
    public static function day(CarbonInterface $at, ?string $locale = null): string
    {
        return self::format($at, 'M j', 'j F', $locale);
    }

    /** "Nov 17, 2026" · "17 نومبر 2026" */
    public static function date(CarbonInterface $at, ?string $locale = null): string
    {
        return self::format($at, 'M j, Y', 'j F Y', $locale);
    }

    /** "Nov 17, 2026 · 9:00 AM" · "17 نومبر 2026 · 9:00 صبح", the time as [$time] (Shell::timeFormat()). */
    public static function dateTime(CarbonInterface $at, string $time, ?string $locale = null): string
    {
        return self::format($at, 'M j, Y · '.$time, 'j F Y · '.$time, $locale);
    }

    /** "9:00 AM" · "9:00 صبح", as [$time] (Shell::timeFormat()). */
    public static function time(CarbonInterface $at, string $time, ?string $locale = null): string
    {
        return self::format($at, $time, $time, $locale);
    }

    /** "November 2026" · "نومبر 2026", from a date or a `Y-m`. */
    public static function month(CarbonInterface|string $month, ?string $locale = null): string
    {
        return self::format(self::monthOf($month), 'F Y', 'F Y', $locale);
    }

    /** "Nov 2026" · "نومبر 2026", from a date or a `Y-m`. */
    public static function shortMonth(CarbonInterface|string $month, ?string $locale = null): string
    {
        return self::format(self::monthOf($month), 'M Y', 'F Y', $locale);
    }

    private static function monthOf(CarbonInterface|string $month): CarbonInterface
    {
        return $month instanceof CarbonInterface ? $month : Carbon::createFromFormat('!Y-m', $month);
    }
}
