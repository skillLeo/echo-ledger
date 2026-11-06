<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /api/devices/report (since v1.5): the device check an app chose to send; a new one replaces the last. */
final class DeviceReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);
        $data = $request->validate([
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'app_version' => ['nullable', 'string', 'max:32'],
            'brand' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'checks' => ['required', 'array', 'min:1', 'max:40'],
            'checks.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'checks.*.state' => ['required', 'string', Rule::in(['ok', 'limited', 'off', 'failed'])],
            'checks.*.detail' => ['nullable', 'string', 'max:300'],
        ]);
        $checks = array_map(fn (array $check): array => [
            'key' => $check['key'],
            'state' => $check['state'],
            'detail' => $check['detail'] ?? null,
        ], $data['checks']);

        $report = DeviceReport::query()->withoutGlobalScopes()->updateOrCreate(['device_id' => $device->id], [
            'user_id' => $device->user_id,
            'workspace_id' => $device->workspace_id,
            'platform' => $data['platform'],
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'checks' => $checks,
            'reported_at' => now(),
        ]);

        return response()->json(['data' => ['reported_at' => $report->reported_at->toIso8601String()]], 201);
    }
}
