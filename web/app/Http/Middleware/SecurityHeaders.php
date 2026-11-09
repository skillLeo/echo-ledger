<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Accounts\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every answer, so they do not depend on the web server configuration.
 *
 * Console pages also get a Content-Security-Policy that only allows this origin. Filament's Alpine.js
 * evaluates its directives with `new Function()`, and Filament renders inline <style>/<script> blocks,
 * so scripts need 'unsafe-inline' and 'unsafe-eval' (Livewire's `csp_safe` build cannot run Filament's
 * expressions); avatars are inline SVG data URIs and the player reads audio from this origin. Since v1.6 the sign-in
 * and sign-up pages also allow Cloudflare Turnstile's script and frame, only while Turnstile is set
 * (App\Services\Accounts\Turnstile): it is the one other origin the console ever loads.
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
            $defaults['Content-Security-Policy'] = self::wantsTurnstile($request) ? self::turnstileCsp() : self::CONSOLE_CSP;
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

    public static function turnstileCsp(): string
    {
        $origin = 'https://challenges.cloudflare.com';

        return str_replace("script-src 'self' 'unsafe-inline' 'unsafe-eval'; ", "script-src 'self' 'unsafe-inline' 'unsafe-eval' {$origin}; frame-src {$origin}; ", self::CONSOLE_CSP);
    }

    private static function wantsTurnstile(Request $request): bool
    {
        if (! $request->routeIs('filament.app.auth.login', 'filament.app.auth.register')) {
            return false;
        }
        try {
            return app(Turnstile::class)->active();
        } catch (\Throwable) {
            return false;
        }
    }
}
