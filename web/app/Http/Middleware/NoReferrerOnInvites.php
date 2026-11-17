<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No referrer from a page whose address may carry an invitation's token (v1.8 P6.3): the sign-up page (`?invite=`,
 * `?join=`) and an email invitation's page. A link to another site (Google, the Terms' sources) then never learns it.
 */
final class NoReferrerOnInvites
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->routeIs('filament.app.auth.register', 'invitations.show', 'invitations.accept', 'join.*')
            || $request->query->has('invite') || $request->query->has('join')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
