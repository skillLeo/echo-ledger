<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every answer, so they do not depend on the web server configuration.
 *
 * Console pages also get a Content-Security-Policy that only allows this origin. Filament's Alpine.js
 * evaluates its directives with `new Function()`, and Filament renders inline <style>/<script> blocks,
 * so scripts need 'unsafe-inline' and 'unsafe-eval' (Livewire's `csp_safe` build cannot run Filament's
 * expressions); avatars are inline SVG data URIs and the player reads audio from this origin.
 */
final class SecurityHeaders
{
    public const CONSOLE_CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."media-src 'self' blob:; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
        ];
        if (! $request->is('api/*')) {
            $defaults['Content-Security-Policy'] = self::CONSOLE_CSP;
        }
        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }
}
