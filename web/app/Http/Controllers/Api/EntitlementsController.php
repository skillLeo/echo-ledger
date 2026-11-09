<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Entitlements\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/me/entitlements` (since v1.6, P1, docs/API.md "Entitlements"): what this device's person may use where it acts. */
final class EntitlementsController extends Controller
{
    public function show(Request $request, Entitlements $entitlements): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        return response()->json($entitlements->payload($device->user, $device->workspace_id));
    }
}
