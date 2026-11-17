<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * The design values where a colour must be written out (a chart's bars): read from resources/css/tokens.css, the one
 * source (tokens/ builds it), so nothing here repeats a value.
 */
final class Tokens
{
    /** @var array<string, string>|null */
    private static ?array $light = null;

    /** The light theme's value of `--el-role-<name>` ("accent-default" → "#0F6E6A"); grey when it is missing. */
    public static function colour(string $name): string
    {
        if (self::$light === null) {
            $css = (string) @file_get_contents(resource_path('css/tokens.css'));
            $root = (string) strstr($css, '@media', true) ?: $css;
            preg_match_all('/--el-role-([a-z-]+):\s*(#[0-9A-Fa-f]{6,8})\s*;/', $root, $m);
            self::$light = array_combine($m[1], $m[2]) ?: [];
        }

        return self::$light[$name] ?? '#8B8D98';
    }
}
