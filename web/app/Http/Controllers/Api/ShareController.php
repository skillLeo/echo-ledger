<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\ShareLink;
use App\Services\ShareLinks;
use App\Services\Views\TranscriptViews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * docs/API.md "Share links" (since v1.1) and "Share links (since v1.5)": make, list and revoke links to a recording or
 * a project, open to anyone, to a password, or to named people.
 */
final class ShareController extends Controller
{
    /** The newest links GET /api/shares lists. */
    public const LIST_LIMIT = 1000;

    public function __construct(private readonly ShareLinks $shares) {}

    /** POST /api/sessions/{id}/shares */
    public function store(Request $request, string $id): JsonResponse
    {
        return $this->made($request, $this->session($request, $id));
    }

    /** POST /api/clients/{id}/shares (since v1.5): a link to a project's recordings. */
    public function storeForProject(Request $request, string $id): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $project = Client::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $project instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Project not found.');
        }

        return $this->made($request, $project);
    }

    /**
     * The v1.1 body (`expires_in`, `password`, `include_audio`) still works: a password alone means a password link,
     * `include_audio` alone decides downloads and the player, and no lifetime is one day. A body with any of the v1.5
     * fields (`audience`, `people`, `allow_download`, `expires_at`) gets a player, downloads only with
     * `allow_download` (else `include_audio`), and no expiry unless one is given.
     */
    private function made(Request $request, CaptureSession|Client $target): JsonResponse
    {
        $data = $request->validate([
            'expires_in' => ['sometimes', 'nullable', 'integer', Rule::in(ShareLinks::LIFETIMES)],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
            'include_audio' => ['sometimes', 'boolean'],
            'allow_download' => ['sometimes', 'boolean'],
            'audience' => ['sometimes', Rule::in(ShareLink::AUDIENCES)],
            'people' => ['sometimes', 'array', 'max:'.ShareLinks::MAX_PEOPLE],
            'people.*' => ['string', 'email', 'max:190'],
            // Since v1.2: open the page in a Show as view (roman, english).
            'view' => ['sometimes', 'nullable', Rule::in(array_keys(TranscriptViews::VIEWS))],
        ]);
        $modern = $request->hasAny(['audience', 'people', 'allow_download', 'expires_at']);
        $password = isset($data['password']) && $data['password'] !== '' ? (string) $data['password'] : null;
        $audience = $data['audience'] ?? ($password !== null ? 'password' : 'public');
        $downloads = $request->has('allow_download') ? $request->boolean('allow_download') : $request->boolean('include_audio');
        $expiresAt = match (true) {
            ! empty($data['expires_at']) => Carbon::parse((string) $data['expires_at'])->utc(),
            ! empty($data['expires_in']) => now()->addSeconds((int) $data['expires_in']),
            $modern => null,
            default => now()->addSeconds(ShareLinks::DEFAULT_LIFETIME),
        };
        $device = $request->user();
        ['link' => $link, 'url' => $url] = $this->shares->make($target, [
            'audience' => $audience,
            'password' => $password,
            'people' => array_values($data['people'] ?? []),
            'include_audio' => $modern || $request->boolean('include_audio'),
            'allow_download' => $downloads,
            'expires_at' => $expiresAt,
            'view' => $data['view'] ?? null,
        ], $device instanceof Device ? $device->name : null);

        return response()->json([...$link->refresh()->toApi(), 'url' => $url], 201);
    }

    /** GET /api/sessions/{id}/shares: every link of the recording, newest first, without the secret. */
    public function index(Request $request, string $id): JsonResponse
    {
        $session = $this->session($request, $id);

        return response()->json(['data' => $session->shareLinks()->with(['captureSession', 'client'])->latest()->get()
            ->map(fn (ShareLink $l): array => $l->toApi())->values()]);
    }

    /**
     * GET /api/shares (since v1.5): every link of the person's recordings and projects in the workspace, newest first; `session_id`
     * or `client_id` narrows it.
     */
    public function all(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'size:26'],
            'client_id' => ['nullable', 'string', 'size:26'],
        ]);
        /** @var Device $device */
        $device = $request->user();
        $links = $this->workspaceLinks($device->workspace_id, $device->user_id)
            ->when(! empty($data['session_id']), fn (Builder $q) => $q->where('capture_session_id', $data['session_id']))
            ->when(! empty($data['client_id']), fn (Builder $q) => $q->where('client_id', $data['client_id']))
            ->with(['captureSession', 'client'])->latest()->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        return response()->json(['data' => $links->map(fn (ShareLink $l): array => $l->toApi())->values()]);
    }

    /** DELETE /api/shares/{id}: the link stops working at once. */
    public function destroy(Request $request, string $id): Response
    {
        /** @var Device $device */
        $device = $request->user();
        $link = $this->workspaceLinks($device->workspace_id, $device->user_id)->find($id);
        if (! $link instanceof ShareLink) {
            throw new ApiError(404, 'share_not_found', 'Share link not found.');
        }
        $this->shares->revoke($link);

        return response()->noContent();
    }

    /**
     * Links of the person's own recordings and projects in the workspace (since v1.5 nobody sees another person's).
     *
     * @return Builder<ShareLink>
     */
    public static function workspaceLinks(int $workspaceId, int $userId): Builder
    {
        return ShareLink::query()->where(fn (Builder $q) => $q
            ->whereHas('captureSession', fn ($s) => $s->where('workspace_id', $workspaceId)->where('user_id', $userId))
            ->orWhereHas('client', fn ($c) => $c->where('workspace_id', $workspaceId)->where('user_id', $userId)));
    }

    private function session(Request $request, string $id): CaptureSession
    {
        /** @var Device $device */
        $device = $request->user();
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        return $session;
    }
}
