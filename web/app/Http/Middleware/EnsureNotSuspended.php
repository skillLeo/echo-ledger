<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use App\Models\Device;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The suspend switch on the device API (since v1.6, P1.5): a suspended account's devices keep their tokens but every
 * call is `403 account_suspended`, until the super admin lifts it.
 */
final class EnsureNotSuspended
{
    public const MESSAGE = 'This account is suspended. Write to the owner of this server to ask why.';

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        // The device's person, loaded once for the request (the routes after this read the same relation).
        if ($device instanceof Device && $device->user instanceof User && $device->user->isSuspended()) {
            throw new ApiError(403, 'account_suspended', self::MESSAGE);
        }

        return $next($request);
    }
}
