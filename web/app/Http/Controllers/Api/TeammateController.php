<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\SentItem;
use App\Services\Sharing\Teammates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** docs/API.md "Sending to a teammate" (since v1.5): send, list what was sent to you, save a copy, remove it. */
final class TeammateController extends Controller
{
    public function __construct(private readonly Teammates $teammates) {}

    /** POST /api/sessions/{id}/send */
    public function sendSession(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        return $this->sent($request, $session);
    }

    /** POST /api/clients/{id}/send */
    public function sendProject(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);
        $project = Client::query()->where('workspace_id', $device->workspace_id)->find($id);
        if (! $project instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Client not found.');
        }

        return $this->sent($request, $project);
    }

    private function sent(Request $request, CaptureSession|Client $target): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:'.Teammates::MAX_RECIPIENTS],
            'to.*' => ['required', 'string', 'email', 'max:190'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $sent = $this->teammates->send($this->device($request)->user, $target, array_values($data['to']), $data['note'] ?? null);

        return response()->json(['sent' => $sent], 201);
    }

    /** GET /api/shared-with-me */
    public function index(Request $request): JsonResponse
    {
        $items = $this->teammates->received($this->device($request)->user);

        return response()->json(['data' => $this->teammates->payloads($items, fn (CaptureSession $s): array => (new LibraryItemResource($s))->resolve($request))]);
    }

    /** POST /api/shared-with-me/{id}/save: a copy in the device's workspace, independent of the original. */
    public function save(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);

        return response()->json($this->teammates->save($device->user, $device->workspace_id, $this->item($request, $id)), 201);
    }

    /** DELETE /api/shared-with-me/{id}: off the list; the sender's recording stays. */
    public function destroy(Request $request, string $id): Response
    {
        $this->teammates->remove($this->item($request, $id));

        return response()->noContent();
    }

    private function item(Request $request, string $id): SentItem
    {
        $item = $this->teammates->item($this->device($request)->user, $id);
        if (! $item instanceof SentItem) {
            throw new ApiError(404, 'not_found', 'This item is not in Shared with me.');
        }

        return $item;
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
