<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\ShareLink;
use App\Services\ShareLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** docs/API.md "Share links" (since v1.1): make, list and revoke view-only links to a recording. */
final class ShareController extends Controller
{
    public function __construct(private readonly ShareLinks $shares) {}

    /** POST /api/sessions/{id}/shares */
    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expires_in' => ['required', 'integer', Rule::in(ShareLinks::LIFETIMES)],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
            'include_audio' => ['sometimes', 'boolean'],
        ]);
        $session = $this->session($request, $id);
        $device = $request->user();
        ['link' => $link, 'url' => $url] = $this->shares->create(
            $session,
            (int) $data['expires_in'],
            $data['password'] ?? null,
            $request->boolean('include_audio'),
            $device instanceof Device ? $device->name : null,
        );

        return response()->json([...$link->refresh()->toApi(), 'url' => $url], 201);
    }

    /** GET /api/sessions/{id}/shares: every link of the recording, newest first, without the secret. */
    public function index(Request $request, string $id): JsonResponse
    {
        $session = $this->session($request, $id);

        return response()->json(['data' => $session->shareLinks()->latest()->get()->map(fn (ShareLink $l): array => $l->toApi())->values()]);
    }

    /** DELETE /api/shares/{id}: the link stops working at once. */
    public function destroy(Request $request, string $id): Response
    {
        /** @var Device $device */
        $device = $request->user();
        $link = ShareLink::query()->whereHas('captureSession', fn ($q) => $q->where('workspace_id', $device->workspace_id))->find($id);
        if (! $link instanceof ShareLink) {
            throw new ApiError(404, 'share_not_found', 'Share link not found.');
        }
        $this->shares->revoke($link);

        return response()->noContent();
    }

    private function session(Request $request, string $id): CaptureSession
    {
        /** @var Device $device */
        $device = $request->user();
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        return $session;
    }
}
