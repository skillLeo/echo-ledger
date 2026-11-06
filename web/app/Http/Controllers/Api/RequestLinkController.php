<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\RequestLink;
use App\Services\Sharing\RequestLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** docs/API.md "Request links" (since v1.5): make, list and revoke links others send files through. */
final class RequestLinkController extends Controller
{
    public function __construct(private readonly RequestLinks $links) {}

    /** POST /api/request-links: `url` is in this answer only. */
    public function store(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'client_id' => ['nullable', 'string', 'size:26'],
            'expires_at' => ['nullable', 'date'],
            'max_files' => ['nullable', 'integer', 'min:1', 'max:'.RequestLinks::MAX_FILES],
            'max_mb' => ['nullable', 'integer', 'min:1', 'max:'.RequestLinks::maxMegabytes()],
        ]);
        $project = null;
        if (! empty($data['client_id'])) {
            $project = Client::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($data['client_id']);
            if (! $project instanceof Client) {
                throw new ApiError(404, 'client_not_found', 'Client not found.');
            }
        }
        ['link' => $link, 'url' => $url] = $this->links->create(
            $device->workspace_id,
            $device->user_id,
            $project,
            $data['title'] ?? null,
            ! empty($data['expires_at']) ? Carbon::parse((string) $data['expires_at'])->utc() : null,
            (int) ($data['max_files'] ?? 20),
            (int) ($data['max_mb'] ?? 100),
        );

        return response()->json(['data' => ['url' => $url, ...$link->refresh()->toApi()]], 201);
    }

    /** GET /api/request-links: the workspace's links, newest first, without their address. */
    public function index(Request $request): JsonResponse
    {
        $links = RequestLink::query()->where('workspace_id', $this->device($request)->workspace_id)->where('user_id', $this->device($request)->user_id)->latest()->orderByDesc('id')->get();

        return response()->json(['data' => $links->map(fn (RequestLink $l): array => $l->toApi())->values()]);
    }

    /** DELETE /api/request-links/{id}: no more files come in through it. */
    public function destroy(Request $request, string $id): Response
    {
        $link = RequestLink::query()->where('workspace_id', $this->device($request)->workspace_id)->where('user_id', $this->device($request)->user_id)->find($id);
        if (! $link instanceof RequestLink) {
            throw new ApiError(404, 'not_found', 'Request link not found.');
        }
        $this->links->revoke($link);

        return response()->noContent();
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
