<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\ActionBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/badge (contract 2.0.0-r, BE-04): the one number on the Activity icon, the same in every app and the web
 * console (ActionBadge): the device's person, in the device's workspace.
 */
final class BadgeController extends Controller
{
    public function __invoke(Request $request, ActionBadge $badge): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        return response()->json($badge->badge($device->user, (int) $device->workspace_id));
    }
}
