<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\AuditLog;
use App\Services\DeviceConfigPayload;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DeviceController extends Controller
{
    /** POST /api/devices/register — public, throttled. */
    public function register(Request $request, PairingService $pairing, DeviceConfigPayload $config): JsonResponse
    {
        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'app_version' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:32'],
        ]);

        [$device, $token] = $pairing->redeem($data['pairing_code'], $data, $request->ip());

        return response()->json([
            'device' => $this->deviceArray($device),
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toAtomString(),
            'config' => $config->build($device),
        ], 201);
    }

    /** POST /api/devices/rotate-token */
    public function rotateToken(Request $request, PairingService $pairing): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $current = $device->currentAccessToken();
        // The replacement carries exactly the abilities of the token that asked for it, never more.
        $abilities = array_values(array_filter((array) $current->abilities, 'is_string'));
        $token = $pairing->issueToken($device, $abilities);
        $current->delete();
        app(AuditLog::class)->record('device_token_rotated', $device->user_id, $device, "Device key renewed: {$device->name}");

        return response()->json([
            'token' => $token->plainTextToken,
            'token_expires_at' => $token->accessToken->expires_at?->toAtomString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function deviceArray(Device $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'platform' => $device->platform->value,
            'source_device' => $device->sourceDevice()->value,
            'created_at' => $device->created_at?->toAtomString(),
        ];
    }
}
