<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\TranscriptionKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * `GET /api/me/entitlements` (since v1.6, P1, docs/API.md "Entitlements"): what this device's person may use where it
 * acts; and the person's own transcription key (`PUT` / `DELETE /api/me/transcription-key`), never sent back.
 */
final class EntitlementsController extends Controller
{
    public function putKey(Request $request, TranscriptionKeys $keys): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in([TranscriptionKeys::PROVIDER])],
            'key' => ['required', 'string', 'max:300'],
        ]);

        return response()->json($keys->put($device->user, $data['provider'], $data['key']));
    }

    public function deleteKey(Request $request, TranscriptionKeys $keys): Response
    {
        /** @var Device $device */
        $device = $request->user();
        $keys->forget($device->user);

        return response()->noContent();
    }

    public function show(Request $request, Entitlements $entitlements): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        return response()->json($entitlements->payload($device->user, $device->workspace_id));
    }
}
