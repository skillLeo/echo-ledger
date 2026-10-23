<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Services\DeviceConfigPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigController extends Controller
{
    /** GET /api/config */
    public function show(Request $request, DeviceConfigPayload $config): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        return response()->json($config->build($device));
    }

    /** GET /api/clients */
    public function clients(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $clients = Client::query()->where('user_id', $device->user_id)->withCount('keyterms')->orderBy('name')->get();

        return response()->json(['data' => $clients->map(fn (Client $c): array => [
            'id' => $c->id,
            'name' => $c->name,
            'color' => $c->color,
            'keyterm_count' => (int) $c->getAttribute('keyterms_count'),
            'updated_at' => $c->updated_at?->toAtomString(),
        ])->values()]);
    }
}
