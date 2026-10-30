<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Http\Resources\TranscriptResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Transcript;
use App\Services\LibraryOrganiser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

final class LibraryController extends Controller
{
    /** GET /api/library — every session of the owner, from every device, plus tombstones when `since` is given. */
    public function index(Request $request, LibraryOrganiser $organiser): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'since' => ['nullable', ...SessionController::instantRules()],
            'source' => ['nullable', 'string', 'max:40'],
            'source_app' => ['nullable', Rule::enum(SourceApp::class)],
            'client_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'tag' => ['nullable', 'string', 'max:40'],
            'bookmarked' => ['nullable', 'boolean'],
            'view' => ['nullable', Rule::in(LibraryOrganiser::VIEWS)],
            'tz' => ['nullable', 'timezone:all'],
            'label' => ['nullable', 'string', 'max:40'],
            'pinned' => ['nullable', 'boolean'],
        ]);
        $cursor = empty($data['cursor']) ? null : self::decodeCursor($data['cursor']);

        $serverTime = now();
        $query = CaptureSession::query()->where('workspace_id', $device->workspace_id)->with([
            'device', 'client', 'transcripts', 'markers:id,capture_session_id,tag,at_ms', 'labels',
            // Only this user's own "opened" row: whether to show the unopened dot (since v1.3).
            'opens' => fn ($q) => $q->where('user_id', $device->user_id),
        ]);

        if (! empty($data['since'])) {
            // Any offset names an instant; the stored timestamps are UTC. Inclusive: server_time has whole seconds, so a
            // change in the same second as the previous sync is delivered again rather than lost (clients merge by id).
            $query->withTrashed()->where('updated_at', '>=', Carbon::parse($data['since'])->utc());
        }
        if (empty($data['status']) && ($data['view'] ?? null) !== 'waiting') {
            // Open sessions (still recording) may yet be discarded: listed only when asked for; tombstones always sync.
            $query->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhere('status', '!=', SessionStatus::Recording->value));
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

        if (! empty($data['tag'])) {
            $query->whereHas('markers', fn ($q) => $q->where('tag', $data['tag']));
        }
        if ($request->boolean('bookmarked')) {
            $query->whereHas('markers');
        }
        if (! empty($data['view'])) {
            $organiser->applyView($query, $data['view'], $data['tz'] ?? null);
        }
        if (! empty($data['label'])) {
            $query->whereHas('labels', fn ($q) => $q->whereRaw('LOWER(label) = ?', [mb_strtolower(trim($data['label']))]));
        }
        if ($request->has('pinned') && $data['pinned'] !== null) {
            $query->where('pinned', $request->boolean('pinned'));
        }

        $page = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['limit'] ?? 50), ['*'], 'cursor', $cursor);

        return response()->json([
            'data' => LibraryItemResource::collection($page->getCollection())->resolve($request),
            'next_cursor' => $page->nextCursor()?->encode(),
            'server_time' => $serverTime->toAtomString(),
        ]);
    }

    /**
     * Only cursors this endpoint issued are accepted: both sort fields present as text, a parsable timestamp and a
     * boolean direction. Anything else is `422 invalid_cursor` (docs/API.md "Library & sync").
     */
    private static function decodeCursor(string $encoded): Cursor
    {
        $cursor = Cursor::fromEncoded($encoded);
        $fields = $cursor instanceof Cursor ? $cursor->toArray() : [];
        $updatedAt = $fields['updated_at'] ?? null;
        $id = $fields['id'] ?? null;
        // The direction is stored as decoded, whatever its type: a forged "yes" must not pass as a boolean.
        $valid = $cursor instanceof Cursor && is_bool($fields['_pointsToNextItems'] ?? null)
            && is_string($updatedAt) && $updatedAt !== '' && is_string($id) && $id !== '';
        if ($valid) {
            try {
                Carbon::parse($updatedAt);
            } catch (Throwable) {
                $valid = false;
            }
        }
        if (! $valid) {
            throw new ApiError(422, 'invalid_cursor', 'The cursor is invalid.', ['errors' => ['cursor' => ['The cursor is invalid.']]]);
        }

        return $cursor;
    }

    /** GET /api/transcripts/{id} */
    public function transcript(Request $request, string $id): TranscriptResource
    {
        /** @var Device $device */
        $device = $request->user();
        $transcript = Transcript::query()
            ->whereHas('captureSession', fn ($q) => $q->where('workspace_id', $device->workspace_id)->whereNull('deleted_at'))
            ->findOrFail($id);

        return new TranscriptResource($transcript);
    }
}
