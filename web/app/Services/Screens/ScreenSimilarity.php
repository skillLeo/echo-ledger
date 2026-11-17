<?php

declare(strict_types=1);

namespace App\Services\Screens;

use GdImage;

/**
 * Whether two screen pictures are the same screen (v1.8 P4.1), kept conservative so a genuinely different screen is
 * never dropped. Both full pictures are shrunk to 640 px wide in grey; they are the same only when at most 2 of those
 * pixels differ by more than 12 shades: the same picture sent again after another compression (WebP, JPEG or PNG
 * give 0), nothing more. One changed digit in a table of a 1920 px screen changes about 8 of them, a changed word 25,
 * a moved box thousands. A thumbnail is too small for this (one changed word vanishes in it), so the pictures
 * themselves are compared. A moving mouse pointer changes a few, so two pictures that differ by it may both be kept:
 * the safe side.
 */
final class ScreenSimilarity
{
    public const WIDTH = 640;

    /** A pixel counts as changed past this many shades of grey (of 255). */
    public const SHADES = 12;

    /** At most this many changed pixels for the same screen. */
    public const MAX_CHANGED = 2;

    /** @return array{0: int, 1: int, 2: string}|null width, height and one byte of grey a pixel, row by row */
    public static function signature(string $picture): ?array
    {
        $image = @imagecreatefromstring($picture);
        if (! $image instanceof GdImage) {
            return null;
        }
        $height = max(1, (int) round(imagesy($image) * self::WIDTH / max(1, imagesx($image))));
        $small = imagecreatetruecolor(self::WIDTH, $height);
        imagecopyresampled($small, $image, 0, 0, 0, 0, self::WIDTH, $height, imagesx($image), imagesy($image));
        unset($image);
        $grey = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $grey .= chr((int) round(0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF)));
            }
        }

        return [self::WIDTH, $height, $grey];
    }

    /**
     * @param  array{0: int, 1: int, 2: string}  $a
     * @param  array{0: int, 1: int, 2: string}  $b
     */
    public static function same(array $a, array $b): bool
    {
        if ($a[0] !== $b[0] || $a[1] !== $b[1]) {
            return false;
        }
        $changed = 0;
        $count = strlen($a[2]);
        for ($i = 0; $i < $count; $i++) {
            if (abs(ord($a[2][$i]) - ord($b[2][$i])) > self::SHADES && ++$changed > self::MAX_CHANGED) {
                return false;
            }
        }

        return true;
    }
}
