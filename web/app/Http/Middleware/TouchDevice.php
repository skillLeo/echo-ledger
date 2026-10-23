<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Records device last-seen (throttled to once a minute to keep writes low on shared hosting). */
final class TouchDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if ($device instanceof Device && ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute()))) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }

        return $next($request);
    }
}
