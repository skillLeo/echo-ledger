<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\ClientAi\ClientWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ask beyond one project (since 2.0.0-o, docs/API.md "Ask about a recording and across recordings"): the same question,
 * answer and citations as `POST /api/clients/{id}/ask`, about one recording of the person's or across all of their own
 * recordings in the device's workspace. The same throttle, plan feature and AI budget apply.
 */
final class AskController extends Controller
{
    public function __construct(private readonly ClientWorkspace $workspace) {}

    /** POST /api/sessions/{id}/ask */
    public function recording(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }
        [$question, $language] = self::question($request);

        return response()->json(['data' => $this->workspace->askRecording($session, $question, $language)]);
    }

    /** POST /api/ask */
    public function all(Request $request): JsonResponse
    {
        $device = $this->device($request);
        [$question, $language] = self::question($request);

        return response()->json(['data' => $this->workspace->askAll($device->workspace_id, $device->user_id, $question, $language)]);
    }

    /** @return array{0: string, 1: string} the question (3-500 characters) and the answer's language (en | roman_urdu) */
    private static function question(Request $request): array
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'language' => ['sometimes', Rule::in(['en', 'roman_urdu'])],
        ]);

        return [trim($data['question']), $data['language'] ?? 'en'];
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
