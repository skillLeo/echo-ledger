<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;

/**
 * The v2.0 console (docs/v2/DESIGN_SYSTEM.md, IA.md) for the person signed in, decided once a request: the new frame
 * (ui_v2.shell: the five-module sidebar, the top bar, the bottom bar, the design values from tokens/ and the console
 * language) and the new Library and recording page (ui_v2.library). With both off the console is exactly as before
 * (tests/Feature/V2/ClassicGoldenTest.php). A person's "Switch back to classic" turns both off.
 */
final class ConsoleV2
{
    /** The console languages under ui_v2.shell, in the words each speaker reads. */
    public const LANGUAGES = ['en' => 'English', 'ur' => 'اردو'];

    /** The new frame, for the person signed in to the customer console. */
    public static function shell(): bool
    {
        return self::flag('ui_v2.shell');
    }

    /** The new Library and recording page. */
    public static function library(): bool
    {
        return self::flag('ui_v2.library');
    }

    /**
     * The three levels of navigation (docs/PLAN_NAV_3_LEVELS.md): the rail, the place's panel and the page's tabs, in
     * place of the sidebar, with the new frame and the new Library together (NavFrame).
     */
    public static function frame(): bool
    {
        return self::shell() && self::library();
    }

    /** The admin panel on the design values (ui_v2.admin), for the member of staff signed in to it. */
    public static function admin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Filament::getCurrentPanel()?->getId() === 'admin' && app(InterfaceFlags::class)->active('ui_v2.admin', $user);
    }

    /**
     * UX-07 and UX-18 (v21_zoom): the account menu's Zoom row. On the web the browser zooms the page (its own keys, which
     * move every menu and the layout's breakpoints with it); a page can't set that zoom, so the row gives the keys.
     */
    public static function zoom(): bool
    {
        return self::shell() && self::flag('v21_zoom');
    }

    /** Whether any of the console's v2.0 parts is on: the design values load then. */
    public static function any(): bool
    {
        return self::shell() || self::library();
    }

    /** The console language under the new frame ('en' without it). */
    public static function language(): string
    {
        if (! self::shell()) {
            return 'en';
        }
        $user = auth()->user();
        $chosen = $user instanceof User && is_array($user->console_preferences['shell'] ?? null) ? ($user->console_preferences['shell']['app_language'] ?? 'en') : 'en';

        return is_string($chosen) && isset(self::LANGUAGES[$chosen]) ? $chosen : 'en';
    }

    /** Whether the console reads right to left. */
    public static function rtl(): bool
    {
        return self::language() === 'ur';
    }

    private static function flag(string $flag): bool
    {
        $user = auth()->user();
        if (! $user instanceof User || Filament::getCurrentPanel()?->getId() !== 'app') {
            return false;
        }

        // Pennant keeps the answer for the rest of the request.
        return app(InterfaceFlags::class)->active($flag, $user);
    }
}
