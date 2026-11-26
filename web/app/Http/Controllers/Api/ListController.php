<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Lists\LabelLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The person's lists (contract 2.0.0-m, docs/API.md "Lists"): the apps' row of lists, each a label with a colour and a
 * place, the same on every device. Always the device's person in the device's workspace.
 */
final class ListController extends Controller
{
    public function __construct(private readonly LabelLists $lists) {}

    /** GET /api/lists: the person's lists in their order, with how many recordings each holds. */
    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json(['data' => $this->lists->index($device->workspace_id, $device->user_id)]);
    }

    /** POST /api/lists: a new, empty list at the end of the row. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $device = $this->device($request);

        return response()->json(['data' => $this->lists->create($device->workspace_id, $device->user_id, $data['name'], $data['color'] ?? null)], 201);
    }

    /** PATCH /api/lists/{key}: rename (every recording in it with it) and/or recolour. */
    public function update(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $device = $this->device($request);

        return response()->json(['data' => $this->lists->update($device->workspace_id, $device->user_id, $key,
            array_key_exists('name', $data), $data['name'] ?? null, array_key_exists('color', $data), $data['color'] ?? null)]);
    }

    /** DELETE /api/lists/{key}: the list goes; its recordings stay, without its label. */
    public function destroy(Request $request, string $key): JsonResponse
    {
        $device = $this->device($request);

        return response()->json($this->lists->delete($device->workspace_id, $device->user_id, $key));
    }

    /** PUT /api/lists/order: every list of the person, once each, in the new order. */
    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keys' => ['present', 'array', 'max:1000'],
            'keys.*' => ['string', 'max:200'],
        ]);
        $device = $this->device($request);

        return response()->json(['data' => $this->lists->order($device->workspace_id, $device->user_id, array_values($data['keys']))]);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
