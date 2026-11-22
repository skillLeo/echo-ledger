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
use App\Models\Client;
use App\Models\Device;
use App\Models\Transcript;
use App\Services\Library\LibraryFacets;
use App\Services\Library\LibraryFilters;
use App\Services\LibraryOrganiser;
use App\Services\Projects\ProjectTree;
use App\Services\Sharing\Teammates;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

final class LibraryController extends Controller
{
    /** Since 2.0.0-p: the views `view` also takes beside LibraryOrganiser::VIEWS (counted by LibraryFacets). */
    public const MORE_VIEWS = ['needs_you', 'unread'];

    /** GET /api/library — every session of the owner, from every device, plus tombstones when `since` is given. */
    public function index(Request $request, LibraryOrganiser $organiser, ProjectTree $projects, Teammates $teammates, LibraryFacets $facets): JsonResponse
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
            'view' => ['nullable', Rule::in([...LibraryOrganiser::VIEWS, ...self::MORE_VIEWS])],
            'tz' => ['nullable', 'timezone:all'],
            'label' => ['nullable', 'string', 'max:40'],
            'pinned' => ['nullable', 'boolean'],
            // Since 2.0.0-i: the person's flag.
            'important' => ['nullable', 'boolean'],
            // Since v1.5: a smart folder, and a project's subfolders with `client_id`.
            'folder' => ['nullable', Rule::in(LibraryOrganiser::FOLDERS)],
            'include_subfolders' => ['nullable', 'boolean'],
            // Since 2.0.0-p: the Library's filters (docs/API.md "Library filters") and its search box.
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $filters = LibraryFilters::normalize($request->input('filters'));
        $cursor = empty($data['cursor']) ? null : self::decodeCursor($data['cursor']);

        $serverTime = now();
        // The `shared` folder (since v1.5) is what others sent this person, from their workspaces, read-only.
        $shared = ($data['folder'] ?? null) === 'shared';
        // Since 2.0.0-p: Needs you and Unread are places of the person's own library, and the filters and the search
        // count only it: with `folder=shared` each is refused, never ignored (docs/API.md "Library filters").
        $place = in_array($data['view'] ?? null, self::MORE_VIEWS, true) ? (string) $data['view'] : null;
        $searching = LibraryFacets::cleanQuery($data['q'] ?? null) !== null;
        if ($shared) {
            foreach (['view' => $place !== null, 'filters' => $filters !== [], 'q' => $searching] as $field => $asked) {
                if ($asked) {
                    $message = 'Needs you, Unread, the filters and the search are for your own recordings, not for what was shared with you.';
                    throw new ApiError(422, 'validation_failed', $message, ['errors' => [$field => [$message]]]);
                }
            }
        }
        $query = ($shared ? $teammates->sharedSessions($device->user) : CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id))->with([
            'device', 'client', 'markers:id,capture_session_id,tag,at_ms', 'labels',
            // Since B6 without the transcripts' lines and search copy, which a row never reads.
            'transcripts' => fn ($q) => $q->select(LibraryItemResource::TRANSCRIPT_COLUMNS),
            // Only this user's own "opened" row: whether to show the unopened dot (since v1.3).
            'opens' => fn ($q) => $q->where('user_id', $device->user_id),
        ])->withCount('screens');
        // Since 2.0.0-p: the item's `facets.status` reads whether an upload failed, with the rows.
        LibraryItemResource::withUploadFailed($query);

        if (! empty($data['since'])) {
            // Any offset names an instant; the stored timestamps are UTC. Inclusive: server_time has whole seconds, so a
            // change in the same second as the previous sync is delivered again rather than lost (clients merge by id).
            $query->withTrashed()->where('updated_at', '>=', Carbon::parse($data['since'])->utc());
        }
        if (empty($data['status']) && ! in_array($data['view'] ?? null, ['waiting', 'needs_you'], true)) {
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
        foreach (['source_app', 'status'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['client_id'])) {
            $project = $request->boolean('include_subfolders')
                ? Client::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($data['client_id']) : null;
            $project instanceof Client
                ? $query->whereIn('client_id', $projects->withDescendants($project))
                : $query->where('client_id', $data['client_id']);
        }
        if (! empty($data['folder']) && ! $shared) {
            $organiser->applyFolder($query, $data['folder']);
        }

        if (! empty($data['tag'])) {
            $query->whereHas('markers', fn ($q) => $q->where('tag', $data['tag']));
        }
        if ($request->boolean('bookmarked')) {
            $query->whereHas('markers');
        }
        if ($place !== null || $filters !== [] || $searching) {
            // Since 2.0.0-p: the place (Needs you keeps its waiting calls, also with filters or a search), the filters
            // and the search, as GET /api/library/facets counts them.
            $facets->apply($query, $device->workspace_id, $device->user_id, $filters, $data['q'] ?? null, $data['tz'] ?? null, $place ?? 'all');
        }
        if (! empty($data['view']) && $place === null) {
            $organiser->applyView($query, $data['view'], $data['tz'] ?? null);
        }
        if (! empty($data['label'])) {
            $query->whereHas('labels', fn ($q) => $q->whereRaw('LOWER(label) = ?', [mb_strtolower(trim($data['label']))]));
        }
        if ($request->has('pinned') && $data['pinned'] !== null) {
            $query->where('pinned', $request->boolean('pinned'));
        }
        if ($request->has('important') && $data['important'] !== null) {
            $query->where('important', $request->boolean('important'));
        }

        $page = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['limit'] ?? 50), ['*'], 'cursor', $cursor);
        // Since 2.0.0-j: an audio-only row's estimate reads its primary recording (length, channels): loaded for those
        // rows only, in one query, so a page without any costs nothing more.
        $audioOnly = $page->getCollection()->filter(fn (CaptureSession $s): bool => ! $s->trashed() && $s->status === SessionStatus::AudioOnly);
        if ($audioOnly->isNotEmpty()) {
            (new EloquentCollection($audioOnly->all()))->load('recordings:id,capture_session_id,role,duration_ms,channels');
        }

        // The sender's project and device stay theirs here too, as in GET /api/sessions/{id}.
        $items = $shared ? $page->getCollection()->map(fn (CaptureSession $session): CaptureSession => Teammates::readOnly($session)) : $page->getCollection();

        return response()->json([
            'data' => LibraryItemResource::collection($items)->resolve($request),
            'next_cursor' => $page->nextCursor()?->encode(),
            'server_time' => $serverTime->toAtomString(),
            // Since 2.0.0-p: how many times the person's saved views changed; another number than last time means read
            // GET /api/views again.
            'views_version' => (int) $device->user->views_version,
        ]);
    }

    /**
     * GET /api/library/facets (since 2.0.0-p): for the filters and the search, how many recordings they keep and how
     * many each option of each section would keep (its own section's filter left out), with "Needs you".
     */
    public function facets(Request $request, LibraryFacets $facets): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'tz' => ['nullable', 'timezone:all'],
        ]);
        $filters = LibraryFilters::normalize($request->input('filters'));

        return response()->json([
            ...$facets->facets($device->workspace_id, $device->user_id, $filters, $data['q'] ?? null, $data['tz'] ?? null),
            // The sidebar's Library views, whatever the filters.
            'library' => $facets->libraryCounts($device->workspace_id, $device->user_id),
            'filters' => (object) array_map(fn (array $f): object => (object) $f, $filters),
            'views_version' => (int) $device->user->views_version,
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
    public function transcript(Request $request, string $id, Teammates $teammates): TranscriptResource
    {
        /** @var Device $device */
        $device = $request->user();
        $transcript = Transcript::query()
            ->whereHas('captureSession', fn ($q) => $q->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->whereNull('deleted_at'))
            ->find($id)
            // Since v1.5: the transcript of a recording a teammate sent this person (read-only).
            ?? Transcript::query()->whereIn('capture_session_id', $teammates->sharedSessions($device->user)->select('capture_sessions.id'))->findOrFail($id);

        return new TranscriptResource($transcript);
    }
}
