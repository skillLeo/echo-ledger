<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The product's name as people read it (since v1.9, L0), from its one place, `config('echoledger.brand')`. Pages and
 * emails write it with `@brand`, code with Brand::name(); a fixed text (a class constant) holds TOKEN and is read
 * through fill(). Technical names never follow it (config/echoledger.php says which).
 */
final class Brand
{
    /** Where a fixed text names the product; fill() puts the name in. */
    public const TOKEN = '{brand}';

    public static function name(): string
    {
        return (string) config('echoledger.brand.name');
    }

    /** The admin panel's name next to its ADMIN label. */
    public static function adminName(): string
    {
        return self::name().' admin';
    }

    /** [$text] with the product's name where it holds TOKEN. */
    public static function fill(string $text): string
    {
        return str_replace(self::TOKEN, self::name(), $text);
    }
}
