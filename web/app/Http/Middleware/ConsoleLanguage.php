<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Support\ConsoleV2;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's language under the new frame (v2.0, ui_v2.shell): Settings › Appearance › Language. Urdu turns the
 * whole page right to left (Filament's own `ur` words say so). Without the new frame the console stays in English.
 */
final class ConsoleLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $language = ConsoleV2::language();
        if ($language !== App::getLocale()) {
            App::setLocale($language);
        }

        return $next($request);
    }
}
