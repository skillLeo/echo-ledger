<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Outside Filament routes: require a super admin who has enrolled app (TOTP) MFA, mirroring the panel rule. */
final class EnsureAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_super_admin && $user->getAppAuthenticationSecret() !== null, 403);

        return $next($request);
    }
}
