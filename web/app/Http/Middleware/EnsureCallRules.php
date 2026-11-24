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
 * The call rules' routes exist only for a person the `call_rules` interface flag is on for (contract 2.0.0-v): for
 * everyone else they answer `404 not_found`, as a route that is not there (like Live Assist's, EnsureLiveAssist).
 */
final class EnsureCallRules
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if (! $device instanceof Device || ! app(InterfaceFlags::class)->active(InterfaceFlags::CALL_RULES, $device->user, $device->workspace_id)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
