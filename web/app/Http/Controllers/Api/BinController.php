<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\RetentionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The 48-hour undo bin and the storage meter for device apps (since v1.1, plan B17). */
final class BinController extends Controller
{
    public function __construct(private readonly RetentionPolicy $policy) {}

    /** GET /api/bin */
    public function index(Request $request): JsonResponse
    {
        $sessions = $this->policy->bin($this->device($request)->user_id);

        return response()->json(['data' => $sessions->map(fn (CaptureSession $s): array => [
            'id' => $s->id,
            'title' => $s->displayTitle(),
            'client' => $s->client ? ['id' => $s->client->id, 'name' => $s->client->name] : null,
            'started_at' => $s->started_at->toAtomString(),
            'duration_ms' => $s->duration_ms,
            'deleted_at' => $s->deleted_at?->toAtomString(),
            'erase_at' => $s->deleted_at?->copy()->addHours(CaptureSession::BIN_HOURS)->toAtomString(),
        ])->values()]);
    }

    /** POST /api/sessions/{id}/restore */
    public function restore(Request $request, string $id): JsonResponse
    {
        $session = $this->policy->restore($this->binned($request, $id));
        $session->load(['device', 'client', 'transcripts', 'markers:id,capture_session_id,tag,at_ms', 'labels']);

        return response()->json(['data' => (new LibraryItemResource($session))->resolve($request)]);
    }

    /** DELETE /api/bin/{id}: erase now instead of after 48 hours. */
    public function destroy(Request $request, string $id): Response
    {
        $this->policy->deleteNow($this->binned($request, $id));

        return response()->noContent();
    }

    /** GET /api/storage */
    public function storage(Request $request): JsonResponse
    {
        return response()->json([
            ...$this->policy->storage($this->device($request)->user_id),
            'retention_days' => $this->policy->daysFor(null),
        ]);
    }

    private function binned(Request $request, string $id): CaptureSession
    {
        $session = CaptureSession::onlyTrashed()->where('user_id', $this->device($request)->user_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        return $session;
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
