<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use App\Models\Device;
use App\Services\Staff\StaffAccess;
use App\Services\System\PlatformNotices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The device API during maintenance and for apps below the lowest version (since v1.6, P2.7): `503 maintenance` with
 * Retry-After for everyone but staff's devices, and `426 update_required` for an app older than its platform's
 * minimum when the admin refuses older apps. GET /api/config always answers, so an app can read why.
 */
final class EnsurePlatformAvailable
{
    public function __construct(private readonly PlatformNotices $notices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if (! $device instanceof Device || $request->routeIs('api.config')) {
            return $next($request);
        }
        $maintenance = $this->notices->maintenance();
        if ($maintenance !== null && ! StaffAccess::isStaff($device->user)) {
            throw new ApiError(503, 'maintenance', $maintenance['message'], ['until' => $maintenance['until']], ['Retry-After' => (string) $this->notices->retryAfter()]);
        }
        $update = $this->notices->updateFor($device);
        if ($update !== null && $update['refused']) {
            throw new ApiError(426, 'update_required', $update['message'], ['minimum' => $update['minimum'], 'url' => $update['url']]);
        }

        return $next($request);
    }
}
