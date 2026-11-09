<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SessionStatus;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Services\Projects\ProjectTree;
use App\Services\Screens\ScreenStore;
use App\Services\Screens\UnreadablePicture;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Screen moments (since v1.5, docs/API.md "Screen moments"): the pictures the desktop keeps of a shared screen during
 * a meeting, on the recording's timeline, and a project's Screens tab.
 */
final class ScreenController extends Controller
{
    /** Minutes the signed picture links stay valid. */
    public const LINK_MINUTES = 30;

    /** Screens per page of a project's Screens tab. */
    public const PAGE = 60;

    /** A moment may be at most this far past the recording's end (the desktop's clock and the recorder's differ a little). */
    public const LATE_MS = 60_000;

    public function __construct(private readonly ScreenStore $store) {}

    /** POST /api/sessions/{id}/screens — a picture of the shared screen at `at_ms`. */
    public function store(Request $request, string $id): JsonResponse
    {
        $session = $this->ownSession($request, $id);
        // Since v1.5 the plan decides: 403 feature_not_in_plan, the desktop keeps recording without screens.
        app(PlanLimits::class)->assertFeature($session->workspace_id, 'screen_moments', $session->user_id);
        $upload = $request->file('image');
        if ($upload instanceof UploadedFile && (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($upload->isValid() && (int) $upload->getSize() > ScreenStore::MAX_BYTES))) {
            throw new ApiError(413, 'too_large', 'A screen picture is at most 3 MB.');
        }
        $data = $request->validate([
            'image' => ['required', 'file'],
            'at_ms' => ['required', 'integer', 'min:0', 'max:'.(self::lengthOf($session) + self::LATE_MS)],
            'width' => ['required', 'integer', 'min:1', 'max:'.ScreenStore::MAX_SIDE],
            'height' => ['required', 'integer', 'min:1', 'max:'.ScreenStore::MAX_SIDE],
            'trigger' => ['required', Rule::in(Screen::TRIGGERS)],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);
        /** @var UploadedFile $upload */
        $path = (string) $upload->getRealPath();
        // The type comes from the bytes: a PNG named .webp is a PNG; a PDF named .webp is refused.
        $picture = ScreenStore::sniff($path);
        if ($picture === null) {
            throw ValidationException::withMessages(['image' => 'Send a WebP, PNG or JPEG picture.']);
        }
        if ($picture['width'] * $picture['height'] > ScreenStore::MAX_PIXELS || max($picture['width'], $picture['height']) > ScreenStore::MAX_SIDE) {
            throw ValidationException::withMessages(['image' => 'The picture has too many pixels. Send it smaller.']);
        }
        if (! hash_equals(strtolower($data['sha256']), (string) hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['sha256' => 'The SHA-256 does not match the picture.']);
        }
        try {
            [$screen, $created] = $this->store->add($session, $path, $picture, [
                'at_ms' => (int) $data['at_ms'], 'width' => (int) $data['width'], 'height' => (int) $data['height'],
                'trigger' => (string) $data['trigger'], 'sha256' => (string) $data['sha256'],
            ]);
        } catch (UnreadablePicture) {
            throw ValidationException::withMessages(['image' => 'The picture could not be read.']);
        }

        return response()->json(['data' => self::payload($screen, ScreenStore::indexOf($screen))], $created ? 201 : 200);
    }

    /** GET /api/sessions/{id}/screens — in time order, numbered from 1; also for a recording a teammate sent. */
    public function index(Request $request, string $id): JsonResponse
    {
        $session = $this->readableSession($request, $id);
        $screens = ScreenStore::ordered($session->id)->get();

        return response()->json(['data' => $screens->values()->map(fn (Screen $s, int $i): array => self::payload($s, $i + 1))->all()]);
    }

    /** DELETE /api/sessions/{id}/screens/{screen} — the picture and its thumbnail are erased at once. */
    public function destroy(Request $request, string $id, string $screen): Response
    {
        $session = $this->ownSession($request, $id);
        $model = ScreenStore::ordered($session->id)->whereKey($screen)->first();
        if (! $model instanceof Screen) {
            throw new ApiError(404, 'not_found', 'Screen not found.');
        }
        $this->store->delete($model);
        $session->touch();

        return response()->noContent();
    }

    /**
     * GET /api/clients/{id}/screens — the project's Screens tab: newest recording first, each recording's screens in
     * time order, 60 a page. `include_subfolders=1` adds its subfolders' recordings. Scoped like the library.
     */
    public function project(Request $request, string $id, ProjectTree $projects): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $client = Client::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $client instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Project not found.');
        }
        $request->validate(['include_subfolders' => ['nullable', 'boolean'], 'cursor' => ['nullable', 'string', 'max:512']]);
        $folders = $request->boolean('include_subfolders') ? $projects->withDescendants($client) : [$client->id];

        $query = Screen::query()->withoutGlobalScope(WorkspaceScope::class)
            ->join('capture_sessions', 'capture_sessions.id', '=', 'screens.capture_session_id')
            ->where('capture_sessions.workspace_id', $device->workspace_id)
            ->where('capture_sessions.user_id', $device->user_id)
            ->whereIn('capture_sessions.client_id', $folders)
            ->whereNull('capture_sessions.deleted_at')
            ->where('capture_sessions.status', '!=', SessionStatus::Recording->value)
            ->select('screens.*', 'capture_sessions.started_at as cursor_started_at');
        $cursor = $request->filled('cursor') ? self::decodeCursor((string) $request->query('cursor')) : null;
        if ($cursor !== null) {
            [$startedAt, $sessionId, $atMs, $screenId] = $cursor;
            $query->where(fn (Builder $q) => $q->where('capture_sessions.started_at', '<', $startedAt)
                ->orWhere(fn (Builder $same) => $same->where('capture_sessions.started_at', $startedAt)->where('capture_sessions.id', '<', $sessionId))
                ->orWhere(fn (Builder $same) => $same->where('capture_sessions.started_at', $startedAt)->where('capture_sessions.id', $sessionId)
                    ->where(fn (Builder $later) => $later->where('screens.at_ms', '>', $atMs)
                        ->orWhere(fn (Builder $tie) => $tie->where('screens.at_ms', $atMs)->where('screens.id', '>', $screenId)))));
        }
        $rows = $query->orderByDesc('capture_sessions.started_at')->orderByDesc('capture_sessions.id')
            ->orderBy('screens.at_ms')->orderBy('screens.id')->limit(self::PAGE + 1)->get();
        $more = $rows->count() > self::PAGE;
        $page = $rows->take(self::PAGE)->values();

        $sessionIds = $page->pluck('capture_session_id')->unique()->values()->all();
        $sessions = CaptureSession::query()->whereIn('id', $sessionIds)->get()->keyBy('id');
        // Numbers within each recording, counted over all its screens (not only this page's).
        $indexes = [];
        $counter = [];
        foreach (Screen::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('capture_session_id', $sessionIds)
            ->orderBy('at_ms')->orderBy('id')->get(['id', 'capture_session_id']) as $row) {
            $indexes[$row->id] = $counter[$row->capture_session_id] = ($counter[$row->capture_session_id] ?? 0) + 1;
        }
        $last = $page->last();

        return response()->json([
            'data' => $page->map(function (Screen $screen) use ($sessions, $indexes): array {
                /** @var CaptureSession $session */
                $session = $sessions[$screen->capture_session_id];

                return self::payload($screen, $indexes[$screen->id] ?? 1) + [
                    'session_id' => $session->id,
                    'session_title' => $session->displayTitle(),
                    'session_started_at' => $session->started_at->toAtomString(),
                ];
            })->all(),
            'next_cursor' => $more && $last instanceof Screen ? self::encodeCursor($last) : null,
        ]);
    }

    /**
     * A screen as the API shows it; the picture links are signed for 30 minutes and need no token.
     *
     * @return array<string, mixed>
     */
    public static function payload(Screen $screen, int $index): array
    {
        $until = now()->addMinutes(self::LINK_MINUTES);

        return [
            'id' => $screen->id,
            'index' => $index,
            'at_ms' => $screen->at_ms,
            'width' => $screen->width,
            'height' => $screen->height,
            'trigger' => $screen->trigger,
            'ocr_text' => $screen->ocr_text,
            'description' => $screen->description,
            'image_url' => URL::temporarySignedRoute('screens.media', $until, ['screen' => $screen->id, 'variant' => 'image']),
            'thumb_url' => URL::temporarySignedRoute('screens.media', $until, ['screen' => $screen->id, 'variant' => 'thumb']),
            'created_at' => $screen->created_at->toAtomString(),
        ];
    }

    /** The recording's length so far: its duration, else its audio's, else the time since it started (still recording). */
    private static function lengthOf(CaptureSession $session): int
    {
        if ($session->duration_ms !== null) {
            return max(0, $session->duration_ms);
        }
        $audio = $session->recordings()->max('duration_ms');

        return is_numeric($audio) ? max(0, (int) $audio) : max(0, (int) $session->started_at->diffInMilliseconds(now()));
    }

    private static function encodeCursor(Screen $screen): string
    {
        $json = (string) json_encode([
            's' => (string) $screen->getAttribute('cursor_started_at'), 'c' => $screen->capture_session_id, 'a' => $screen->at_ms, 'i' => $screen->id,
        ]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Only cursors this endpoint issued: every field present with its type. Anything else is `422 invalid_cursor`.
     *
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private static function decodeCursor(string $encoded): array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $fields = $json === false ? null : json_decode($json, true);
        if (! is_array($fields) || ! is_string($fields['s'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fields['s']) !== 1
            || ! is_string($fields['c'] ?? null) || ! is_int($fields['a'] ?? null) || ! is_string($fields['i'] ?? null)) {
            throw new ApiError(422, 'invalid_cursor', 'The cursor is invalid.', ['errors' => ['cursor' => ['The cursor is invalid.']]]);
        }

        return [$fields['s'], $fields['c'], $fields['a'], $fields['i']];
    }

    /** A recording of the device's workspace that is not deleted; anything else is `404 session_not_found`. */
    private function ownSession(Request $request, string $id): CaptureSession
    {
        /** @var Device $device */
        $device = $request->user();

        return CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->findOrFail($id);
    }

    /** The workspace's recording, or one a teammate sent this person (read-only); anything else as an unknown id. */
    private function readableSession(Request $request, string $id): CaptureSession
    {
        /** @var Device $device */
        $device = $request->user();
        $own = CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if ($own instanceof CaptureSession) {
            return $own;
        }

        return app(Teammates::class)->readable($device->user, $id) ?? throw (new ModelNotFoundException)->setModel(CaptureSession::class, [$id]);
    }
}
