<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Services\DeviceConfigPayload;
use App\Stt\LanguageChoice;
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
        $clients = Client::query()->where('workspace_id', $device->workspace_id)->withCount(['keyterms', 'captureSessions'])
            ->orderByDesc('pinned')->orderBy('name')->get();

        return response()->json(['data' => $clients->map(fn (Client $c): array => self::clientPayload($c))->values()]);
    }

    /**
     * One client folder as devices see it (the counts come from `withCount`).
     *
     * @return array<string, mixed>
     */
    public static function clientPayload(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'color' => $client->color,
            'avatar' => $client->avatar,
            'pinned' => (bool) $client->pinned,
            'retention_days' => $client->retention_days,
            // Since v1.1.2: the client's default language (null = the console default / automatic).
            'language' => LanguageChoice::normalize($client->language),
            // Since v1.4: call notes and memory after each call for this client.
            'ai_notes' => (bool) ($client->ai_notes ?? true),
            'keyterm_count' => (int) $client->getAttribute('keyterms_count'),
            'session_count' => (int) $client->getAttribute('capture_sessions_count'),
            'updated_at' => $client->updated_at?->toAtomString(),
        ];
    }
}
