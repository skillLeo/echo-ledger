<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Library\SavedViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved views (contract 2.0.0-p, docs/API.md "Saved views"): the person's My views, the same on every device. Always
 * the device's person in the device's workspace; another person's view answers as an unknown one (404 view_not_found).
 * Every change answers the new `views_version`, which the library sync also carries.
 */
final class SavedViewController extends Controller
{
    public function __construct(private readonly SavedViews $views) {}

    /** GET /api/views: the person's views in their order. */
    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'data' => $this->views->index($device->workspace_id, $device->user_id),
            'views_version' => $this->views->version($device->workspace_id, $device->user_id),
            'limit' => SavedViews::MAX,
        ]);
    }

    /** POST /api/views: a new view at the end (200 with the same view for a `client_ref` already used). */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['client_ref' => ['sometimes', 'nullable', 'string', 'max:64']]);
        $device = $this->device($request);
        $made = $this->views->create($device->workspace_id, $device->user_id, $request->only(['name', 'filters', 'search', 'sort', 'group', 'density', 'client_ref']));

        return response()->json(['data' => $made['view'], 'views_version' => $this->views->version($device->workspace_id, $device->user_id)], $made['created'] ? 201 : 200);
    }

    /** PATCH /api/views/{id}: rename, or change the filters, the search or how the list is shown. */
    public function update(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);
        $view = $this->views->update($device->workspace_id, $device->user_id, $id, $request->only(['name', 'filters', 'search', 'sort', 'group', 'density']));

        return response()->json(['data' => $view, 'views_version' => $this->views->version($device->workspace_id, $device->user_id)]);
    }

    /** DELETE /api/views/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $device = $this->device($request);

        return response()->json($this->views->delete($device->workspace_id, $device->user_id, $id));
    }

    /** PUT /api/views/order: every view of the person, once each, in the new order. */
    public function order(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['present', 'array', 'max:100'], 'ids.*' => ['string', 'max:64']]);
        $device = $this->device($request);

        return response()->json([
            'data' => $this->views->order($device->workspace_id, $device->user_id, array_values($data['ids'])),
            'views_version' => $this->views->version($device->workspace_id, $device->user_id),
        ]);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
