<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Staff\StaffAccess;
use App\Services\System\PlatformNotices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app (/app) during maintenance (since v1.6, P2.7): customers get the pause page (503, Retry-After); staff keep
 * working, with a reminder at the top. Signing out still works.
 */
final class EnsureAppAvailable
{
    public function __construct(private readonly PlatformNotices $notices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = $this->notices->maintenance();
        if ($maintenance === null || StaffAccess::isStaff() || $request->routeIs('filament.app.auth.logout')) {
            return $next($request);
        }

        return response()->view('maintenance', $maintenance, 503, ['Retry-After' => (string) $this->notices->retryAfter()]);
    }
}
