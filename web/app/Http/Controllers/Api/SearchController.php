<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\LibrarySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GET /api/search (since v1.1): titles and transcripts of every device, with the moments that match. */
final class SearchController extends Controller
{
    public function index(Request $request, LibrarySearch $search): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'client_id' => ['nullable', 'string', 'size:26'],
            'source_app' => ['nullable', Rule::enum(SourceApp::class)],
            'source_device' => ['nullable', Rule::enum(SourceDevice::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tag' => ['nullable', 'string', 'max:40'],
            'bookmarked' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        if (LibrarySearch::terms($data['q']) === []) {
            return response()->json(['data' => [], 'next_page' => null, 'engine' => 'like']);
        }
        $limit = (int) ($data['limit'] ?? 20);
        $page = (int) ($data['page'] ?? 1);
        $result = $search->search($device->workspace_id, $data['q'], [...$data, 'bookmarked' => $request->boolean('bookmarked')], $limit, $page);

        return response()->json([
            'data' => $result['items']->map(fn (CaptureSession $session): array => [
                'session' => (new LibraryItemResource($session))->resolve($request),
                'hits' => $result['hits'][$session->id]['hits'],
                'hit_count' => $result['hits'][$session->id]['count'],
            ])->values(),
            'next_page' => $result['more'] ? $page + 1 : null,
            'engine' => $search->engine,
        ]);
    }
}
