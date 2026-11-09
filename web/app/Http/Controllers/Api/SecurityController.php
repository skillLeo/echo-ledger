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
        $events = AuditEvent::query()->where('user_id', $device->user_id)->latest('id')->limit(50)->get();

        $names = $devices->mapWithKeys(fn (Device $d): array => [$d->id => $d->readableName()]);

        return response()->json([
            'devices' => $devices->map(fn (Device $d): array => $d->listItem($device->id))->values(),
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
            throw new ApiError(409, 'current_device', 'This is the device you are using. Sign out of it in its settings instead.');
        }
        // Since v1.6 the row stays as "Signed out by you" (its tokens stop working at once).
        if ($target->signed_out_at === null) {
            $target->signOut('self');
        }

        return response()->noContent();
    }

    /** PATCH /api/devices/{id} (since v1.6): renames one of the person's own devices of this workspace. */
    public function renameDevice(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:60']]);
        $target = Device::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $target instanceof Device) {
            throw new ApiError(404, 'device_not_found', 'Device not found.');
        }
        $target->forceFill(['name' => $data['name'], 'name_chosen' => true])->save();
        app(AuditLog::class)->record('device_renamed', $device->user_id, $target, 'Device renamed: '.$target->name);

        return response()->json($target->listItem($device->id));
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
