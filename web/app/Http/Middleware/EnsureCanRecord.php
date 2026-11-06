<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Services\Workspaces\Workspaces;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The device API's side of App\Policies\WorkspacePolicy (since v1.5, docs/API.md "Workspaces, roles and privacy"): a
 * route names the row of the table it needs (`record` by default, `ownContent` for making or changing one's own
 * recordings and projects, `saveShared`), and a device whose person may not do it there gets `403 not_allowed`. A
 * viewer's device opens what is shared with its person and records, uploads or changes nothing.
 */
final class EnsureCanRecord
{
    public function __construct(private readonly Workspaces $workspaces) {}

    public function handle(Request $request, Closure $next, string $ability = 'record'): Response
    {
        $device = $request->user();
        if ($device instanceof Device) {
            $this->workspaces->assertMay($device->user, $device->workspace_id, $ability);
        }

        return $next($request);
    }
}
