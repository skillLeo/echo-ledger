<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Entitlements\UpgradeRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Upgrade requests (since v1.6, P1.4, docs/API.md "Upgrade requests"): only ever the device's own person's. */
final class UpgradeRequestController extends Controller
{
    public function __construct(private readonly UpgradeRequests $requests) {}

    /** POST /api/upgrade-requests — `sessions:write`, throttled (5 a day per person). */
    public function store(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1000'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);
        $created = $this->requests->create($device->user, $device->workspace_id, $data['plan'], $data['message'] ?? null, $data['promo_code'] ?? null);

        return response()->json($this->requests->payload($created, created: true), 201);
    }

    /** GET /api/upgrade-requests/latest — `config:read`: the newest request, or `404 not_found`. */
    public function latest(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $latest = $this->requests->latestFor($device->user);
        if ($latest === null) {
            throw new ApiError(404, 'not_found', 'No upgrade request yet.');
        }

        return response()->json($this->requests->payload($latest));
    }
}
