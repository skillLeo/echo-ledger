<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Support\Shell;
use App\Services\SettingsRepository;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How Settings and Plan & usage write numbers, money, sizes, dates and times (since v1.7): "Rs 2,999", "120 MB",
 * "1.4 GB", "9 Dec" (this year) or "9 Dec 2026" in rows, "9 December 2026" in sentences, and times in the person's
 * own clock ("3:42 pm" or "15:42"), always in the console's time zone.
 */
final class Formats
{
    public static function rupees(int $amount): string
    {
        return 'Rs '.number_format($amount);
    }

    /** "640 KB", "120 MB", "1 GB", "1.4 GB": no ".0", no decimals under a gigabyte. */
    public static function bytes(int|float $bytes): string
    {
        $bytes = max(0, (float) $bytes);

        return match (true) {
            $bytes >= 1024 ** 3 => self::trim(round($bytes / 1024 ** 3, 1)).' GB',
            $bytes >= 1024 ** 2 => number_format(round($bytes / 1024 ** 2)).' MB',
            $bytes >= 1024 => number_format(max(1, round($bytes / 1024))).' KB',
            $bytes > 0 => '1 KB',
            default => '0 MB',
        };
    }

    /** "30 days", "1 year". */
    public static function days(int $days): string
    {
        return match (true) {
            $days % 365 === 0 => ($days / 365 === 1 ? '1 year' : ($days / 365).' years'),
            default => $days === 1 ? '1 day' : number_format($days).' days',
        };
    }

    /** "3 hours", "90 minutes". */
    public static function fileLength(int $minutes): string
    {
        return $minutes % 60 === 0 ? ($minutes === 60 ? '1 hour' : ($minutes / 60).' hours') : number_format($minutes).' minutes';
    }

    /** "9 Dec" this year, "9 Dec 2026" otherwise (rows). */
    public static function shortDate(DateTimeInterface|string|null $at, bool $year = false): string
    {
        $date = self::local($at);
        if ($date === null) {
            return '';
        }

        return $date->format(! $year && $date->year === self::local(now())?->year ? 'j M' : 'j M Y');
    }

    /** "9 December 2026" (sentences). */
    public static function longDate(DateTimeInterface|string|null $at): string
    {
        return self::local($at)?->format('j F Y') ?? '';
    }

    /** "3:42 pm" or "15:42", as the person chose under Appearance › Times. */
    public static function time(DateTimeInterface|string|null $at): string
    {
        $date = self::local($at);
        if ($date === null) {
            return '';
        }

        return Shell::preferences()['clock'] === '24h' ? $date->format('H:i') : strtolower($date->format('g:i a'));
    }

    public static function local(DateTimeInterface|string|null $at): ?Carbon
    {
        if ($at === null || $at === '') {
            return null;
        }

        return Carbon::parse($at)->setTimezone(app(SettingsRepository::class)->displayTimezone());
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
