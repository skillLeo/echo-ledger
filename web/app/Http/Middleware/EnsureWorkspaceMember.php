<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Workspaces\Workspaces;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console requests (panel, Livewire updates, media and export routes): a signed-in user who is a super admin or a
 * member of a workspace. Repairs `current_workspace_id` to a workspace the user still belongs to, so the workspace
 * scope never acts for one they were removed from (docs/HANDOFF.md "B20 design").
 *
 * Two-factor sign-in is optional; when a user has enabled it, the Filament login already asked for the code before
 * this session existed, so it is not checked again here.
 */
final class EnsureWorkspaceMember
{
    public function __construct(private readonly Workspaces $workspaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $workspace = $this->workspaces->current($user);
        abort_if($workspace === null && ! $user->is_super_admin, 403);

        return $next($request);
    }
}
