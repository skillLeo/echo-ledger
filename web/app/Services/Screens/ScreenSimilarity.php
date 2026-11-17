<?php

declare(strict_types=1);

namespace App\Services\Screens;

use GdImage;

/**
 * Whether two screen pictures are the same screen (v1.8 P4.1), kept conservative so a genuinely different screen is
 * never dropped. Both full pictures are shrunk to 640 px wide in grey (never enlarged, and only within the bounds of
 * signature()); they are the same only when at most 2 of those pixels differ by more than 12 shades: the same picture sent again after another compression (WebP, JPEG or PNG
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

    /** A picture is compared only within these bounds; anything else is kept as a screen, never compared. */
    public const MAX_PIXELS = ScreenStore::MAX_PIXELS;

    /** The longer side at most this many times the shorter: a screen, not a strip. */
    public const MAX_ASPECT = 4;

    /**
     * The picture shrunk (never enlarged) to at most 640 px wide in grey, or null when it is not compared: unreadable,
     * not the size it claims ([$width] × [$height]), over 25 megapixels, or a strip (more than 4 to 1). Its size is
     * read from its header before GD decodes it, so no picture can make the work large.
     *
     * @return array{0: int, 1: int, 2: string}|null width, height and one byte of grey a pixel, row by row
     */
    public static function signature(string $picture, ?int $width = null, ?int $height = null): ?array
    {
        $size = @getimagesizefromstring($picture);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }
        [$w, $h] = [(int) $size[0], (int) $size[1]];
        if (($width !== null && $w !== $width) || ($height !== null && $h !== $height)
            || $w * $h > self::MAX_PIXELS || max($w, $h) > self::MAX_ASPECT * min($w, $h)) {
            return null;
        }
        $image = @imagecreatefromstring($picture);
        if (! $image instanceof GdImage || imagesx($image) !== $w || imagesy($image) !== $h) {
            return null;
        }
        // Down only: a picture narrower than 640 px keeps its size (at most 640 × 2560 by the aspect bound).
        $scale = min(1.0, self::WIDTH / $w);
        $outW = max(1, (int) round($w * $scale));
        $outH = max(1, (int) round($h * $scale));
        $small = imagecreatetruecolor($outW, $outH);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $outW, $outH, $w, $h);
        unset($image);
        $grey = '';
        for ($y = 0; $y < $outH; $y++) {
            for ($x = 0; $x < $outW; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $grey .= chr((int) round(0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF)));
            }
        }

        return [$outW, $outH, $grey];
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
