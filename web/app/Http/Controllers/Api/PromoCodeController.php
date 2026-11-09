<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Entitlements\PromoCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/promo-codes/check` (since v1.6.2, docs/API.md "Promo codes"): whether this device's person may use a code,
 * for which plan and at what price. Throttled (10 a minute per person, 30 an hour per network); never lists codes, never
 * says how many uses are left or who else used one.
 */
final class PromoCodeController extends Controller
{
    public function check(Request $request, PromoCodes $codes): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'plan' => ['nullable', 'string', 'max:32'],
        ]);

        return response()->json($codes->check($device->user, $data['code'], $data['plan'] ?? null));
    }
}
