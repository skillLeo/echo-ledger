<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Services\InterfaceFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Live Assist's device routes exist only for a person the `live_assist` interface flag is on for (since v1.9 L1):
 * for everyone else they answer `404 not_found`, as a route that is not there.
 */
final class EnsureLiveAssist
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if (! $device instanceof Device || ! app(InterfaceFlags::class)->active(InterfaceFlags::LIVE_ASSIST, $device->user, $device->workspace_id)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
