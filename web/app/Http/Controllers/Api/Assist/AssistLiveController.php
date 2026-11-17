<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Assist;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\AssistSession;
use App\Models\Device;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Assist\AssistTransport;
use App\Services\Assist\Http\EventStream;
use App\Services\Assist\Http\LiveHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /api/assist/sessions/{id}/live` — HTTP mode (docs/API_ASSIST.md 1.9.0-q): one app message in the body, its
 * answer streamed back as server-sent events. `sessions:write`; only the person's own active session whose transport
 * is `http`.
 */
final class AssistLiveController extends Controller
{
    /** Streams open at once for one session. */
    public const MAX_STREAMS = 4;

    public function __construct(private readonly LiveHandler $handler) {}

    public function live(Request $request, string $id): StreamedResponse
    {
        $session = $this->session($request, $id);
        $message = $request->json()->all();
        if (! is_string($message['type'] ?? null) || ! in_array($message['type'], LiveHandler::TYPES, true)) {
            throw new ApiError(422, 'bad_message', 'That message is not supported.');
        }
        // Each message is a request: 10 a second per session, and at most 4 streams open.
        $rate = 'assist-live:'.$session->id;
        if (RateLimiter::tooManyAttempts($rate, 10)) {
            throw new ApiError(429, 'rate_limited', 'Too many messages. Send less often.', headers: ['Retry-After' => '1']);
        }
        RateLimiter::hit($rate, 1);
        $open = 'assist-live-open:'.$session->id;
        Cache::add($open, 0, EventStream::MAX_SECONDS + 20);
        if ((int) Cache::increment($open) > self::MAX_STREAMS) {
            Cache::decrement($open);
            throw new ApiError(429, 'rate_limited', 'Too many open streams for this session.', headers: ['Retry-After' => '1']);
        }

        return EventStream::respond(function (EventStream $stream) use ($session, $message, $open): void {
            try {
                $this->handler->handle($session, $message, $stream);
            } finally {
                Cache::decrement($open);
            }
        });
    }

    private function session(Request $request, string $id): AssistSession
    {
        /** @var Device $device */
        $device = $request->user();
        $session = AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $session instanceof AssistSession) {
            throw new NotFoundHttpException;
        }
        if ($session->ended()) {
            throw new ApiError(409, 'session_ended', 'This Live Assist session has ended.');
        }
        if ($session->transport !== AssistTransport::TRANSPORT_HTTP) {
            throw new ApiError(426, 'app_update_required', 'Live Assist needs the latest version of the app.');
        }

        return $session;
    }
}
