<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outside Filament routes: require a signed-in super admin, mirroring the panel rule.
 *
 * Two-factor sign-in is optional; when an admin has enabled it, the Filament login already
 * asked for the code before this session existed, so it is not checked again here.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_super_admin, 403);

        return $next($request);
    }
}
