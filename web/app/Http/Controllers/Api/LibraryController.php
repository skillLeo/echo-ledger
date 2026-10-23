<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Http\Resources\TranscriptResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Transcript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class LibraryController extends Controller
{
    /** GET /api/library — every session of the owner, from every device, plus tombstones when `since` is given. */
    public function index(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:40'],
            'source_app' => ['nullable', Rule::enum(SourceApp::class)],
            'client_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $serverTime = now();
        $query = CaptureSession::query()->where('user_id', $device->user_id)->with(['device', 'client', 'transcripts']);

        if (! empty($data['since'])) {
            $query->withTrashed()->where('updated_at', '>', Carbon::parse($data['since']));
        }
        if (! empty($data['source'])) {
            $sources = array_values(array_filter(array_map(
                fn (string $s) => SourceDevice::tryFrom(trim($s))?->value,
                explode(',', $data['source']),
            )));
            $query->whereIn('source_device', $sources === [] ? ['__none__'] : $sources);
        }
        foreach (['source_app', 'client_id', 'status'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        $page = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['limit'] ?? 50), ['*'], 'cursor', $data['cursor'] ?? null);

        return response()->json([
            'data' => LibraryItemResource::collection($page->getCollection())->resolve($request),
            'next_cursor' => $page->nextCursor()?->encode(),
            'server_time' => $serverTime->toAtomString(),
        ]);
    }

    /** GET /api/transcripts/{id} */
    public function transcript(Request $request, string $id): TranscriptResource
    {
        /** @var Device $device */
        $device = $request->user();
        $transcript = Transcript::query()
            ->whereHas('captureSession', fn ($q) => $q->where('user_id', $device->user_id)->whereNull('deleted_at'))
            ->findOrFail($id);

        return new TranscriptResource($transcript);
    }
}
