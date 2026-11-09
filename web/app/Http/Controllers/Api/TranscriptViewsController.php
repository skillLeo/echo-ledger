<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Transcript;
use App\Services\Sharing\Teammates;
use App\Services\Views\TranscriptViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Show as" (since v1.2): `GET /api/transcripts/{id}/views` reads the Roman Urdu and English views and their state;
 * `POST` starts making them (Claude API, on the queue).
 */
final class TranscriptViewsController extends Controller
{
    public function show(Request $request, string $id, TranscriptViews $views): JsonResponse
    {
        return response()->json($views->state($this->transcript($request, $id, readOnly: true)));
    }

    public function store(Request $request, string $id, TranscriptViews $views): JsonResponse
    {
        $transcript = $this->transcript($request, $id);
        try {
            $state = $views->request($transcript);
        } catch (ApiError $e) {
            throw $e; // since v1.6: 403 feature_not_in_plan
        } catch (RuntimeException $e) {
            throw new ApiError(409, 'views_unavailable', $e->getMessage());
        }

        return response()->json($state, $state['status'] === 'ready' ? 200 : 202);
    }

    /** The workspace's transcript; to read (not to make views), also one of a recording a teammate sent (v1.5). */
    private function transcript(Request $request, string $id, bool $readOnly = false): Transcript
    {
        /** @var Device $device */
        $device = $request->user();
        $transcript = Transcript::query()
            ->whereHas('captureSession', fn ($q) => $q->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->whereNull('deleted_at'))
            ->find($id);
        if (! $transcript instanceof Transcript && $readOnly) {
            $transcript = Transcript::query()->whereIn('capture_session_id', app(Teammates::class)->sharedSessions($device->user)->select('capture_sessions.id'))->find($id);
        }
        if (! $transcript instanceof Transcript) {
            throw new ApiError(404, 'transcript_not_found', 'Transcript not found.');
        }

        return $transcript;
    }
}
