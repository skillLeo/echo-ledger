<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Services\Workspaces\Workspaces;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recording and upload routes (since v1.5, docs/API.md "Workspaces, roles and privacy"): a viewer's device opens
 * what is shared with its person and records nothing, so these answer it `403 not_allowed`.
 */
final class EnsureCanRecord
{
    public function __construct(private readonly Workspaces $workspaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if ($device instanceof Device) {
            $this->workspaces->assertCanRecord($device->user, $device->workspace_id);
        }

        return $next($request);
    }
}
