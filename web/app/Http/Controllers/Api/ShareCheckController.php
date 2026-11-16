<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\ShareCheckResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/diagnostics/share-checks (since v1.8.1): one Share Check result, sent only when the person allows
 * diagnostics. The token decides the device and its person; ids in the body are never read, and anything else the body
 * carries (a picture, a window title) is dropped, never stored.
 */
final class ShareCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);
        $data = $request->validate([
            'platform' => ['required', 'string', Rule::in(ShareCheckResult::PLATFORMS)],
            'os_build' => ['required', 'string', 'max:64'],
            'app_version' => ['required', 'string', 'max:32'],
            'method' => ['required', 'string', Rule::in(ShareCheckResult::METHODS)],
            'result' => ['required', 'string', Rule::in(ShareCheckResult::RESULTS)],
            'reason' => ['nullable', 'string', Rule::in(ShareCheckResult::REASONS)],
            'meeting_app' => ['nullable', 'string', Rule::in(ShareCheckResult::MEETING_APPS)],
            'meeting_app_version' => ['nullable', 'string', 'max:32'],
            'share_mode' => ['nullable', 'string', Rule::in(ShareCheckResult::SHARE_MODES)],
        ]);

        $result = ShareCheckResult::query()->create([
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'platform' => $data['platform'],
            'os_build' => $data['os_build'],
            'app_version' => $data['app_version'],
            'method' => $data['method'],
            'result' => $data['result'],
            'reason' => $data['reason'] ?? null,
            'meeting_app' => $data['meeting_app'] ?? null,
            'meeting_app_version' => $data['meeting_app_version'] ?? null,
            'share_mode' => $data['share_mode'] ?? null,
        ]);

        return response()->json(['data' => ['id' => $result->id]], 201);
    }
}
