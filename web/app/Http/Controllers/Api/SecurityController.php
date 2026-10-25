<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The security centre for device apps (since v1.1, plan B16): paired devices and the recent audit log. */
final class SecurityController extends Controller
{
    /** GET /api/security */
    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);
        // The person's own devices in this workspace; owners manage everyone's from the console.
        $devices = Device::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->orderByDesc('last_seen_at')->get();
        $names = $devices->pluck('name', 'id');
        $events = AuditEvent::query()->where('user_id', $device->user_id)->latest('id')->limit(50)->get();

        return response()->json([
            'devices' => $devices->map(fn (Device $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'platform' => $d->platform->value,
                'app_version' => $d->app_version,
                'last_seen_at' => $d->last_seen_at?->toAtomString(),
                'paired_at' => $d->created_at?->toAtomString(),
                'current' => $d->id === $device->id,
            ])->values(),
            'events' => $events->map(fn (AuditEvent $e): array => [
                'id' => $e->id,
                'event' => $e->event,
                'label' => AuditLog::label($e->event),
                'summary' => $e->summary,
                'actor' => $e->actor,
                'device' => $e->device_id !== null ? ($names[$e->device_id] ?? 'A removed device') : null,
                'at' => $e->created_at->toAtomString(),
            ])->values(),
        ]);
    }

    /** DELETE /api/devices/{id}: revokes another paired device of the owner. */
    public function revokeDevice(Request $request, string $id): Response
    {
        $device = $this->device($request);
        $target = Device::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $target instanceof Device) {
            throw new ApiError(404, 'device_not_found', 'Device not found.');
        }
        if ($target->id === $device->id) {
            throw new ApiError(409, 'current_device', 'This is the device you are using. Unpair it in its settings instead.');
        }
        $target->delete();

        return response()->noContent();
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
