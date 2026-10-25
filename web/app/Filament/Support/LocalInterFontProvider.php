<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\FontProviders\Contracts\FontProvider;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Serves the console font from this server instead of a font CDN (A6.W27).
 *
 * Filament ships Inter as self-hosted woff2 files and publishes them with `php artisan filament:assets`
 * (public/fonts/filament/filament/inter, declared there as "Inter Variable"). This provider declares the same
 * files under the panel's family name, so `->font('Inter')` renders with them. When the files are not published
 * it adds nothing and the browser uses the system sans-serif stack from the theme.
 */
final class LocalInterFontProvider implements FontProvider
{
    public const PUBLISHED_DIR = 'fonts/filament/filament/inter';

    /** @var array<string, string> */
    private static array $css = [];

    public function getHtml(string $family, ?string $url = null): Htmlable
    {
        $css = self::css($family);

        return new HtmlString($css === '' ? '' : '<style>'.$css.'</style>');
    }

    /** @font-face rules for `$family` pointing at Filament's published Inter files, or '' when they are missing. */
    public static function css(string $family): string
    {
        if (preg_match('/^[A-Za-z0-9 -]{1,64}$/', $family) !== 1) {
            return '';
        }
        $base = rtrim(asset(self::PUBLISHED_DIR), '/').'/';
        $key = $family.'|'.$base;
        if (array_key_exists($key, self::$css)) {
            return self::$css[$key];
        }

        $path = public_path(self::PUBLISHED_DIR.'/index.css');
        $source = is_file($path) ? (string) file_get_contents($path) : '';
        $faces = [];
        if (preg_match_all('/@font-face\s*\{[^}]*\}/', $source, $matches) > 0) {
            foreach ($matches[0] as $face) {
                $face = (string) preg_replace('/font-family:\s*["\']?[^;"\'}]+["\']?/', "font-family:'{$family}'", $face, 1);
                $face = (string) preg_replace('/url\((["\']?)\.\//', 'url($1'.$base, $face);
                $faces[] = $face;
            }
        }

        return self::$css[$key] = implode('', $faces);
    }
}
