<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Assist;

use App\Enums\SessionStatus;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Jobs\MakeAssistReportJob;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistTurn;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Assist\AssistPlans;
use App\Services\Assist\AssistStt;
use App\Services\Assist\RelayToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Live Assist sessions (docs/API_ASSIST.md "Sessions"): start one (live with a recording, or Practice), refresh its
 * tokens, send its final turns, end it, read it and its report, delete it, and say what was done with a suggestion.
 * Only the device's own person's sessions, in the device's workspace; anything else answers 404.
 */
final class AssistSessionController extends Controller
{
    public function __construct(private readonly AssistPlans $plans, private readonly RelayToken $relay, private readonly AssistStt $stt) {}

    /** POST /api/assist/sessions — `stt:token`. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prep_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'mode' => ['sometimes', 'nullable', Rule::in(AssistPrep::MODES)],
            'platform' => ['required', Rule::in(AssistSession::PLATFORMS)],
            'recording_session_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'practice' => ['sometimes', 'boolean'],
        ]);
        $device = $this->device($request);
        $practice = (bool) ($data['practice'] ?? false);
        if ($practice && ($data['recording_session_id'] ?? null) !== null) {
            throw new ApiError(422, 'validation_failed', 'Practice records nothing.', ['errors' => ['recording_session_id' => ['Practice records nothing.']]]);
        }
        $prep = ($data['prep_id'] ?? null) !== null ? $this->prep($device, (string) $data['prep_id']) : null;
        $recording = ($data['recording_session_id'] ?? null) !== null ? $this->recording($device, (string) $data['recording_session_id']) : null;
        // Practice runs the Practice mode on every plan; a live session the mode asked for, its prep's or Balanced.
        $mode = $practice ? $this->plans->modes($device->workspace_id, true)[0] : (string) ($data['mode'] ?? ($prep !== null ? $prep->mode : 'balanced'));
        $this->plans->assertMode($device->workspace_id, $mode, $practice);
        $this->plans->assertLeft($device->workspace_id, $device->user_id, $practice);

        $session = DB::transaction(function () use ($device, $data, $practice, $prep, $recording, $mode): AssistSession {
            if (! $practice) {
                $this->endAbandoned($device);
                $running = $this->query($device)->where('status', 'active')->where('practice', false)->lockForUpdate()->exists();
                if ($running) {
                    throw new ApiError(409, 'session_active', 'Live Assist is already on in another call. End it there first.');
                }
            }
            $session = AssistSession::query()->create([
                'workspace_id' => $device->workspace_id,
                'user_id' => $device->user_id,
                'device_id' => $device->id,
                'assist_prep_id' => $prep?->id,
                'capture_session_id' => $recording?->id,
                'mode' => $mode,
                'practice' => $practice,
                'platform' => (string) $data['platform'],
                'auto_saved' => $prep !== null,
                'status' => 'active',
                'started_at' => now(),
                'last_token_at' => now(),
            ]);
            $prep?->forceFill(['status' => 'done', 'armed_until' => null])->save();

            return $session;
        });

        return response()->json([
            'session' => ['id' => $session->id, 'auto_saved' => $session->auto_saved, 'mode' => $session->mode, 'practice' => $session->practice],
            ...$this->tokens($session),
            'plan' => ['modes' => $this->plans->modes($device->workspace_id, $practice), 'seconds_left' => $this->plans->left($device->workspace_id, $device->user_id, $practice)['seconds_left']],
        ], 201)->header('Cache-Control', 'no-store');
    }

    /** POST /api/assist/sessions/{id}/token — `stt:token`: new relay and captions blocks; the budget as it is now. */
    public function token(Request $request, string $id): JsonResponse
    {
        $session = $this->active($request, $id);
        $session->forceFill(['last_token_at' => now()])->save();

        return response()->json($this->tokens($session))->header('Cache-Control', 'no-store');
    }

    /** POST /api/assist/sessions/{id}/turns — `sessions:write`: final turns; a client_ref seen before is ignored. */
    public function turns(Request $request, string $id): JsonResponse
    {
        $session = $this->active($request, $id);
        $data = $request->validate([
            'turns' => ['required', 'array', 'min:1', 'max:50'],
            'turns.*.client_ref' => ['required', 'string', 'max:64'],
            'turns.*.channel' => ['required', Rule::in(['me', 'other'])],
            'turns.*.start_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'turns.*.end_ms' => ['required', 'integer', 'min:0', 'max:86400000', 'gte:turns.*.start_ms'],
            'turns.*.text' => ['required', 'string', 'max:4000'],
            'turns.*.language' => ['sometimes', 'nullable', 'string', 'max:16'],
            'turns.*.is_question' => ['sometimes', 'boolean'],
        ]);
        $accepted = 0;
        foreach ($data['turns'] as $turn) {
            $stored = AssistTurn::query()->firstOrCreate(
                ['assist_session_id' => $session->id, 'client_ref' => (string) $turn['client_ref']],
                ['channel' => $turn['channel'], 'start_ms' => (int) $turn['start_ms'], 'end_ms' => (int) $turn['end_ms'], 'text' => (string) $turn['text'],
                    'language' => $turn['language'] ?? null, 'is_question' => (bool) ($turn['is_question'] ?? false)],
            );
            $accepted += $stored->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json(['accepted' => $accepted]);
    }

    /** POST /api/assist/sessions/{id}/end — `sessions:write`. */
    public function end(Request $request, string $id): JsonResponse
    {
        $session = $this->active($request, $id);
        self::close($session, now());
        MakeAssistReportJob::dispatch($session->id);

        return response()->json(['report' => 'queued']);
    }

    /** GET /api/assist/sessions?limit= — `library:read`. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $device = $this->device($request);
        $this->endAbandoned($device);
        $sessions = $this->query($device)->orderByDesc('started_at')->limit((int) ($data['limit'] ?? 20))->get();

        return response()->json(['data' => $sessions->map(fn (AssistSession $s): array => self::payload($s))->values()->all()]);
    }

    /** GET /api/assist/sessions/{id} — `library:read`. */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(self::payload($this->find($request, $id)));
    }

    /** GET /api/assist/sessions/{id}/report — `library:read`; `404 report_not_ready` until the job is done. */
    public function report(Request $request, string $id): JsonResponse
    {
        $session = $this->find($request, $id);
        if ($session->report_status !== 'ready' || $session->report === null) {
            throw new ApiError(404, 'report_not_ready', 'The report is not ready yet.');
        }

        return response()->json(['session_id' => $session->id, ...$session->report]);
    }

    /** DELETE /api/assist/sessions/{id} — `sessions:write`: the session and every word of it. */
    public function destroy(Request $request, string $id): Response
    {
        $session = $this->find($request, $id);
        if (! $session->ended()) {
            self::close($session, now());
        }
        $session->turns()->delete();
        $session->suggestions()->delete();
        $session->forceFill(['report' => null, 'summary' => null, 'facts' => null, 'report_status' => 'none'])->save();
        $session->delete();

        return response()->noContent();
    }

    /** POST /api/assist/sessions/{id}/suggestions/{suggestionId}/action — `sessions:write`. */
    public function action(Request $request, string $id, string $suggestionId): Response
    {
        $session = $this->find($request, $id);
        if (preg_match('/^[A-Za-z0-9._:\/-]{1,128}$/', $suggestionId) !== 1) {
            throw new NotFoundHttpException;
        }
        $data = $request->validate(['action' => ['required', Rule::in(AssistSuggestion::ACTIONS)]]);
        AssistSuggestion::query()->updateOrCreate(
            ['assist_session_id' => $session->id, 'suggestion_id' => $suggestionId],
            ['action' => $data['action'], 'action_at' => now()],
        );

        return response()->noContent();
    }

    /** @return array<string, mixed> a session as the apps read it */
    public static function payload(AssistSession $session): array
    {
        return [
            'id' => $session->id,
            'prep_id' => $session->assist_prep_id,
            'recording_session_id' => $session->capture_session_id,
            'mode' => $session->mode,
            'practice' => $session->practice,
            'platform' => $session->platform,
            'auto_saved' => $session->auto_saved,
            'status' => $session->status,
            'started_at' => $session->started_at->toAtomString(),
            'ended_at' => $session->ended_at?->toAtomString(),
            'seconds' => $session->seconds(),
            'report' => $session->report_status === 'none' ? null : $session->report_status,
        ];
    }

    /** Ends [$session] at [$at]: its assist time is counted from then on. */
    public static function close(AssistSession $session, \DateTimeInterface $at): void
    {
        $session->forceFill([
            'status' => 'ended',
            'ended_at' => $at,
            'seconds_used' => max(0, (int) $session->started_at->diffInSeconds($at, true)),
            'report_status' => 'queued',
        ])->save();
    }

    /** @return array{relay: array<string, string>, stt: array<string, mixed>|null} */
    private function tokens(AssistSession $session): array
    {
        $prep = $session->prep;
        $language = $prep instanceof AssistPrep ? $prep->language_help : 'off';

        return ['relay' => $this->relay->block($session), 'stt' => $this->stt->block($session, $language !== 'off')];
    }

    /**
     * A live session whose app stopped refreshing its token (the last token long expired) is over: it ends where its
     * last token did, so its time is not counted on.
     */
    private function endAbandoned(Device $device): void
    {
        $cutoff = now()->subSeconds((int) config('assist.abandoned_after_seconds', 1200));
        $this->query($device)->where('status', 'active')->where(fn (Builder $q) => $q->where('last_token_at', '<', $cutoff)->orWhere(
            fn (Builder $q) => $q->whereNull('last_token_at')->where('started_at', '<', $cutoff)))
            ->each(function (AssistSession $stale): void {
                $at = ($stale->last_token_at ?? $stale->started_at)->copy()->addSeconds((int) config('assist.token_ttl_seconds', 900));
                self::close($stale, $at->isFuture() ? now() : $at);
                MakeAssistReportJob::dispatch($stale->id);
            });
    }

    /** @return Builder<AssistSession> */
    private function query(Device $device): Builder
    {
        return AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id);
    }

    private function find(Request $request, string $id): AssistSession
    {
        $session = $this->query($this->device($request))->find($id);
        if (! $session instanceof AssistSession) {
            throw new NotFoundHttpException;
        }

        return $session;
    }

    /** A session that is still on; `409 session_ended` otherwise. */
    private function active(Request $request, string $id): AssistSession
    {
        $session = $this->find($request, $id);
        if ($session->ended()) {
            throw new ApiError(409, 'session_ended', 'This Live Assist session has ended.');
        }

        return $session;
    }

    private function prep(Device $device, string $id): AssistPrep
    {
        $prep = AssistPrep::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $prep instanceof AssistPrep) {
            throw new NotFoundHttpException;
        }

        return $prep;
    }

    /** The recording made at the same time: a session of this device's person, in this workspace, still recording. */
    private function recording(Device $device, string $id): CaptureSession
    {
        $recording = CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $device->workspace_id)
            ->where('user_id', $device->user_id)->find($id);
        if (! $recording instanceof CaptureSession) {
            throw new NotFoundHttpException;
        }
        if ($recording->status !== SessionStatus::Recording) {
            throw new ApiError(422, 'validation_failed', 'That recording is not running.', ['errors' => ['recording_session_id' => ['That recording is not running.']]]);
        }

        return $recording;
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
