<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of the console's signed-out pages (sign-in, sign-up, password reset), since v2.1: the language the
 * person picked on the sign-in page (kept in a cookie for signed-out pages only), else the browser's Accept-Language,
 * else English. Signed in, this does nothing: the account's console language (ConsoleLanguage) always wins.
 *
 * Persistent, so the sign-in form's own requests (its errors and notices) read in the same language.
 */
final class VisitorLanguage
{
    public const COOKIE = 'el_visitor_language';

    /** The languages a visitor can have, each named in itself (the picker shows these). */
    public const NAMES = ['en' => 'English', 'ur' => 'اردو'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Filament::auth()->check()) {
            return $next($request);
        }

        $picked = $request->query('lang');
        if (is_string($picked) && isset(self::NAMES[$picked])) {
            Cookie::queue(self::COOKIE, $picked, 60 * 24 * 365);
            $language = $picked;
        } else {
            $kept = $request->cookie(self::COOKIE);
            $language = is_string($kept) && isset(self::NAMES[$kept])
                ? $kept
                : ($request->getPreferredLanguage(array_keys(self::NAMES)) ?? 'en');
        }

        if ($language !== App::getLocale()) {
            App::setLocale($language);
        }

        return $next($request);
    }
}
