<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MissedRecording;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * POST /api/devices/missed-recordings (since v1.8, P0 0.2): a call the app saw but did not record. The token decides
 * the device, its person and its workspace; ids in the body are never read, and anything else the body carries (a
 * name, a number) is dropped, never stored. The same `client_ref` from the same device is the same row (200), so a
 * retry never makes a second Activity item.
 */
final class MissedRecordingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);
        $data = $request->validate([
            'client_ref' => ['required', 'string', 'max:64'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'app_version' => ['nullable', 'string', 'max:32'],
            'source_app' => ['required', 'string', Rule::in(MissedRecording::sourceApps())],
            'call_started_at' => ['required', ...SessionController::instantRules(), self::recentPast()],
            'call_duration_s' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'reason' => ['required', 'string', Rule::in(array_keys(MissedRecording::REASONS))],
            'detail' => ['nullable', 'string', 'max:300'],
        ]);

        $existing = self::sent($device, $data['client_ref']);
        if ($existing !== null) {
            return self::answer($existing, 200);
        }
        try {
            $missed = MissedRecording::query()->create([
                'user_id' => $device->user_id,
                'device_id' => $device->id,
                'workspace_id' => $device->workspace_id,
                'client_ref' => $data['client_ref'],
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'source_app' => MissedRecording::storedSourceApp($data['source_app']),
                // A clock a little ahead of the server's is let through, and kept as now.
                'call_started_at' => Carbon::parse($data['call_started_at'])->utc()->min(now()),
                'call_duration_s' => isset($data['call_duration_s']) ? (int) $data['call_duration_s'] : null,
                'reason' => $data['reason'],
                'detail' => $data['detail'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // The same event sent twice at once: the other request stored it a moment ago, and posted its item.
            return self::answer(self::sent($device, $data['client_ref']) ?? throw $e, 200);
        }

        return self::answer($missed, 201);
    }

    /** This device's own row for the event, whatever workspace is current: the device is the key. */
    private static function sent(Device $device, string $clientRef): ?MissedRecording
    {
        return MissedRecording::query()->withoutGlobalScopes()->where('device_id', $device->id)->where('client_ref', $clientRef)->first();
    }

    private static function answer(MissedRecording $missed, int $status): JsonResponse
    {
        return response()->json(['data' => ['id' => $missed->id]], $status);
    }

    /** Not in the future (beyond a few minutes of clock drift) and at most 30 days old. */
    private static function recentPast(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            try {
                $at = Carbon::parse((string) $value);
            } catch (Throwable) {
                return; // the instant rules before this one report it
            }
            if ($at->gt(now()->addMinutes(MissedRecording::CLOCK_DRIFT_MINUTES))) {
                $fail('The :attribute field must not be in the future.');
            } elseif ($at->lt(now()->subDays(MissedRecording::MAX_AGE_DAYS))) {
                $fail('The :attribute field must be at most '.MissedRecording::MAX_AGE_DAYS.' days old.');
            }
        };
    }
}
