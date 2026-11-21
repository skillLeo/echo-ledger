<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\TranscribeLater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Audio only and "Transcribe later" (contract 1.8.0-e): one recording, many, and the estimate. Own recordings only. */
final class TranscribeLaterController extends Controller
{
    public function __construct(private readonly TranscribeLater $later) {}

    /** POST /api/sessions/{id}/transcribe — 202 with the session (now processing) and its transcript. */
    public function one(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }
        $transcript = $this->later->start($session);
        $session->refresh()->load(['device', 'client', 'transcripts', 'markers:id,capture_session_id,tag,at_ms', 'labels',
            'opens' => fn ($q) => $q->where('user_id', $device->user_id)]);

        return response()->json([
            'session' => (new LibraryItemResource($session))->resolve($request),
            'transcript' => ['id' => $transcript->id, 'kind' => $transcript->kind->value, 'provider' => $transcript->provider,
                'status' => $transcript->status->value, 'language' => $transcript->language],
        ], 202);
    }

    /** POST /api/sessions/transcribe — "Transcribe selected": 202 with what started and what was skipped. */
    public function many(Request $request): JsonResponse
    {
        return response()->json($this->later->startMany($this->device($request), $this->ids($request)), 202);
    }

    /** POST /api/sessions/transcribe-estimate — what transcribing them would use; nothing changes. */
    public function estimate(Request $request): JsonResponse
    {
        return response()->json($this->later->estimateMany($this->device($request), $this->ids($request)));
    }

    /** @return list<string> */
    private function ids(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.TranscribeLater::MAX_BULK],
            'ids.*' => ['required', 'string', 'size:26'],
        ]);

        return array_values(array_map('strval', $data['ids']));
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
