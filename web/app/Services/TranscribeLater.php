<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Scopes\OwnerScope;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\TranscriptionKeys;
use App\Services\Usage\UsageLedger;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Audio only and "Transcribe later" (contract 1.8.0-e): what a transcript of an audio-only recording would use and
 * the minutes left, and starting it, one or many, with the usual routing (FinalTranscriptCoordinator). Minutes are
 * the plan's minutes: the recording's length rounded up to the minute, times the channels the engine bills; nothing
 * when it would run on an on-device engine or the person's own key, which count for no minutes.
 */
final class TranscribeLater
{
    public const MAX_BULK = 50;

    /** How long a start waits for another start of the same workspace to finish (tests make it 0). */
    public static int $lockWaitSeconds = 10;

    public function __construct(private readonly FinalTranscriptCoordinator $coordinator, private readonly PlanLimits $limits) {}

    /** The plan minutes a transcript of [$session] would use if it started now. */
    public function minutes(CaptureSession $session): int
    {
        ['provider' => $provider, 'own_key' => $ownKey] = $this->coordinator->plan($session, $this->platformTranscription((int) $session->workspace_id, (int) $session->user_id));
        if ($ownKey || ! UsageLedger::isBillable($provider)) {
            return 0;
        }
        $primary = $session->relationLoaded('recordings')
            ? $session->recordings->first(fn ($r) => $r->role === RecordingRole::Primary)
            : $session->recordings()->where('role', RecordingRole::Primary->value)->first();
        $ms = (int) ($primary->duration_ms ?? $session->duration_ms ?? 0);

        return (int) ceil(max(0, $ms) / 60000) * UsageLedger::estimatedChannels($provider, (int) ($primary->channels ?? 1));
    }

    /**
     * The minutes left this month for this person in the workspace; null = no limit. The transcripts already started and
     * not finished count too, at their estimate (the ledger counts a transcript only when it completes), so a second
     * "Transcribe" a moment after the first cannot spend the same minutes again. Read once a request unless [$fresh].
     */
    public function minutesLeft(int $workspaceId, int $userId, bool $fresh = false): ?int
    {
        $key = 'el.minutes-left.'.$workspaceId.'.'.$userId;
        if (! $fresh && request()->attributes->has($key)) {
            /** @var int|null */
            return request()->attributes->get($key);
        }
        $limit = $this->limits->limit($workspaceId, 'monthly_minutes', $userId);
        $left = $limit === null ? null
            : max(0, (int) floor((float) $limit - $this->limits->minutesUsed($workspaceId) - $this->minutesInFlight($workspaceId)));
        // On trial minutes (a plan without transcription on EchoLedger): no more than the trial has left either.
        $entitlements = app(Entitlements::class);
        if (! $entitlements->allows($workspaceId, $userId, 'platform_transcription') && ($trial = $entitlements->trialMinutesLeft($workspaceId, $userId)) > 0) {
            $trialLeft = max(0, $trial - $this->minutesInFlight($workspaceId, $userId));
            $left = $left === null ? $trialLeft : min($left, $trialLeft);
        }
        request()->attributes->set($key, $left);

        return $left;
    }

    /**
     * Whether the server's engines transcribe for this person (Entitlements::platformTranscription), asked once a web
     * request (a Library page of audio-only rows asks it once); asked every time anywhere else (a queue worker lives on).
     */
    private function platformTranscription(int $workspaceId, int $userId): bool
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return app(Entitlements::class)->platformTranscription($workspaceId, $userId);
        }
        $key = 'el.platform-transcription.'.$workspaceId.'.'.$userId;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, app(Entitlements::class)->platformTranscription($workspaceId, $userId));
        }

        return (bool) request()->attributes->get($key);
    }

    /** The estimated plan minutes of the workspace's (or one person's) final transcripts started and not finished yet. */
    private function minutesInFlight(int $workspaceId, ?int $userId = null): int
    {
        $running = [TranscriptStatus::Pending->value, TranscriptStatus::Submitted->value, TranscriptStatus::Processing->value, TranscriptStatus::AwaitingDevice->value];
        $minutes = 0;
        Transcript::query()->where('kind', TranscriptKind::Final->value)->whereIn('status', $running)
            ->whereIn('capture_session_id', CaptureSession::acrossPeople()->where('workspace_id', $workspaceId)
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))->select('id'))
            ->with(['captureSession' => fn ($q) => $q->withoutGlobalScopes([WorkspaceScope::class, OwnerScope::class]), 'captureSession.recordings'])
            ->limit(1000)->get()
            ->each(function (Transcript $t) use (&$minutes): void {
                $provider = $t->providerKey();
                $session = $t->getRelation('captureSession');
                if ($provider === null || ! $session instanceof CaptureSession || ! UsageLedger::isBillable($provider) || (bool) (($t->meta ?? [])['own_key'] ?? false)) {
                    return;
                }
                $primary = $session->recordings->first(fn ($r) => $r->role === RecordingRole::Primary);
                $ms = (int) ($primary->duration_ms ?? $session->duration_ms ?? 0);
                $minutes += (int) ceil(max(0, $ms) / 60000) * UsageLedger::estimatedChannels($provider, (int) ($primary->channels ?? 1));
            });

        return $minutes;
    }

    /**
     * Runs [$work] alone for the workspace: the budget check and the claims of one start never interleave with
     * another's, so two requests cannot both spend the last minutes. `429 rate_limited` when another holds it too long.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function alone(int $workspaceId, callable $work): mixed
    {
        // A cache that holds nothing between requests (array, null) cannot keep two requests apart: refuse to start
        // rather than spend the same minutes twice.
        $store = Cache::getStore();
        if (! app()->runningUnitTests() && ($store instanceof ArrayStore || $store instanceof NullStore)) {
            throw new ApiError(503, 'server_busy', 'Transcription cannot start right now. Try again later.', [], ['Retry-After' => '60']);
        }
        try {
            return Cache::lock('transcribe-later:'.$workspaceId, 30)->block(self::$lockWaitSeconds, $work);
        } catch (LockTimeoutException) {
            throw new ApiError(429, 'rate_limited', 'Another transcription is being started. Try again in a moment.', [], ['Retry-After' => '5']);
        }
    }

    /**
     * A Library item's `transcribe_estimate`: null unless the recording is audio only.
     *
     * @return array{minutes: int, minutes_left: int|null}|null
     */
    public function estimate(CaptureSession $session): ?array
    {
        if ($session->trashed() || $session->status !== SessionStatus::AudioOnly) {
            return null;
        }

        return ['minutes' => $this->minutes($session), 'minutes_left' => $this->minutesLeft((int) $session->workspace_id, (int) $session->user_id)];
    }

    /**
     * The transcript of an audio-only recording starts now. It checks the recording's state, key and budget, not who
     * asks: the API reaches it only through the person's own recordings behind `ownContent`. Anything else (the web
     * console) calls startFor(), which checks both.
     */
    public function start(CaptureSession $session): Transcript
    {
        if ($session->status !== SessionStatus::AudioOnly) {
            throw self::notAudioOnly();
        }
        $this->assertKey($session);
        $needed = $this->minutes($session);

        return $this->alone((int) $session->workspace_id, function () use ($session, $needed): Transcript {
            $this->assertBudget((int) $session->workspace_id, (int) $session->user_id, $needed);

            return $this->claimAndStart($session) ?? throw self::notAudioOnly();
        });
    }

    /**
     * POST /api/sessions/transcribe: the person's audio-only recordings among [$ids] start, all or none for the budget.
     *
     * @param  list<string>  $ids
     * @return array{started: list<string>, skipped: list<array{id: string, code: string}>, minutes: int}
     */
    public function startMany(Device $device, array $ids): array
    {
        return $this->startManyIn((int) $device->workspace_id, (int) $device->user_id, $ids);
    }

    /**
     * For the web console: [$person] starts the transcript of one of their own audio-only recordings in the workspace.
     * A viewer is refused (`403 not_allowed`, as the API's `ownContent`), and anyone else's recording, a teammate's
     * included, is `404 session_not_found`.
     */
    public function startFor(User $person, int $workspaceId, string $sessionId): Transcript
    {
        app(Workspaces::class)->assertMay($person, $workspaceId, 'ownContent');
        $session = CaptureSession::query()->withoutGlobalScopes([WorkspaceScope::class, OwnerScope::class])
            ->where('workspace_id', $workspaceId)->where('user_id', $person->id)->find($sessionId);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        return $this->start($session);
    }

    /**
     * For the web console: "Transcribe selected" as [$person], checked as startFor() checks one, each id against their
     * own recordings (anyone else's is skipped as not_found).
     *
     * @param  list<string>  $ids
     * @return array{started: list<string>, skipped: list<array{id: string, code: string}>, minutes: int}
     */
    public function startManyFor(User $person, int $workspaceId, array $ids): array
    {
        app(Workspaces::class)->assertMay($person, $workspaceId, 'ownContent');

        return $this->startManyIn($workspaceId, (int) $person->id, $ids);
    }

    /**
     * For the web console: what transcribing [$person]'s own audio-only recordings among [$ids] would use.
     *
     * @param  list<string>  $ids
     * @return array{minutes: int, minutes_left: int|null, items: list<array{id: string, minutes: int}>}
     */
    public function estimateManyFor(User $person, int $workspaceId, array $ids): array
    {
        app(Workspaces::class)->assertMay($person, $workspaceId, 'ownContent');

        return $this->estimateIn($workspaceId, (int) $person->id, $ids);
    }

    /**
     * @param  list<string>  $ids
     * @return array{started: list<string>, skipped: list<array{id: string, code: string}>, minutes: int}
     */
    private function startManyIn(int $workspaceId, int $userId, array $ids): array
    {
        [$eligible, $skipped] = $this->sort($workspaceId, $userId, $ids);
        $minutes = (int) $eligible->sum(fn (CaptureSession $s): int => $this->minutes($s));
        if ($eligible->isEmpty()) {
            return ['started' => [], 'skipped' => $skipped, 'minutes' => 0];
        }
        // Nothing starts when any of them would need a key the person does not have.
        $eligible->each(fn (CaptureSession $s) => $this->assertKey($s));

        return $this->alone($workspaceId, function () use ($workspaceId, $userId, $eligible, $skipped, $minutes): array {
            $this->assertBudget($workspaceId, $userId, $minutes);
            $started = [];
            $spent = 0;
            foreach ($eligible as $session) {
                try {
                    // Queued, never the fast path: up to 50 starts must not run after this response in this web worker.
                    $transcript = $this->claimAndStart($session, queued: true);
                } catch (Throwable) {
                    // This one could not start (and went back to audio only); the others still do.
                    $skipped[] = ['id' => $session->id, 'code' => 'not_started'];

                    continue;
                }
                if ($transcript !== null) {
                    $started[] = $session->id;
                    $spent += $this->minutes($session);
                } else {
                    // Another request started it a moment ago.
                    $skipped[] = ['id' => $session->id, 'code' => 'not_audio_only'];
                }
            }

            return ['started' => $started, 'skipped' => $skipped, 'minutes' => $spent];
        });
    }

    /**
     * POST /api/sessions/transcribe-estimate: what transcribing the person's audio-only recordings among [$ids] would use
     * (the ones bulk would start); nothing changes.
     *
     * @param  list<string>  $ids
     * @return array{minutes: int, minutes_left: int|null, items: list<array{id: string, minutes: int}>}
     */
    public function estimateMany(Device $device, array $ids): array
    {
        return $this->estimateIn((int) $device->workspace_id, (int) $device->user_id, $ids);
    }

    /**
     * @param  list<string>  $ids
     * @return array{minutes: int, minutes_left: int|null, items: list<array{id: string, minutes: int}>}
     */
    private function estimateIn(int $workspaceId, int $userId, array $ids): array
    {
        [$eligible] = $this->sort($workspaceId, $userId, $ids);
        $items = $eligible->map(fn (CaptureSession $s): array => ['id' => $s->id, 'minutes' => $this->minutes($s)])->values()->all();

        return ['minutes' => array_sum(array_column($items, 'minutes')), 'minutes_left' => $this->minutesLeft($workspaceId, $userId), 'items' => $items];
    }

    /**
     * The person's audio-only recordings among [$ids] (in the order asked), and why each other id is left out.
     *
     * @param  list<string>  $ids
     * @return array{0: Collection<int, CaptureSession>, 1: list<array{id: string, code: string}>}
     */
    private function sort(int $workspaceId, int $userId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        // Explicit ids, so the same whoever is signed in: only this person's recordings in this workspace.
        $found = CaptureSession::query()->withoutGlobalScopes([WorkspaceScope::class, OwnerScope::class])
            ->where('workspace_id', $workspaceId)->where('user_id', $userId)
            ->whereIn('id', $ids)->with(['recordings', 'client'])->get()->keyBy('id');
        $eligible = collect();
        $skipped = [];
        foreach ($ids as $id) {
            $session = $found->get($id);
            if (! $session instanceof CaptureSession) {
                $skipped[] = ['id' => $id, 'code' => 'not_found'];
            } elseif ($session->status !== SessionStatus::AudioOnly) {
                $skipped[] = ['id' => $id, 'code' => 'not_audio_only'];
            } else {
                $eligible->push($session);
            }
        }

        return [$eligible, $skipped];
    }

    /**
     * Takes the recording out of audio only in one statement (so two taps never start two transcripts), then starts its
     * transcript. Null when it was no longer audio only. [$queued]: on the queue, never the fast path (the bulk starts,
     * FinalTranscriptCoordinator::start).
     */
    private function claimAndStart(CaptureSession $session, bool $queued = false): ?Transcript
    {
        $claimed = CaptureSession::query()->whereKey($session->id)->where('status', SessionStatus::AudioOnly->value)
            ->update(['status' => SessionStatus::Processing->value, 'transcribe' => true, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }
        $session->refresh();
        $existing = $session->finalTranscript()->first();
        try {
            return $this->coordinator->start($session, $existing, queued: $queued);
        } catch (Throwable $e) {
            // Never left half started: the recording goes back to audio only, and its final transcript leaves the running
            // statuses (deleted if this start made it, failed otherwise), so nothing requeues it later (stt:reconcile) to
            // transcribe and charge a recording shown as audio only, and its minutes stop counting as in flight.
            $final = Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Final->value)
                ->where('status', TranscriptStatus::Pending->value)->whereNull('provider_job_id');
            $existing === null
                ? $final->delete()
                : $final->update(['status' => TranscriptStatus::Failed->value, 'error_code' => TranscriptErrorCode::Interrupted->value]);
            CaptureSession::query()->whereKey($session->id)->where('status', SessionStatus::Processing->value)
                ->update(['status' => SessionStatus::AudioOnly->value, 'transcribe' => false]);

            throw $e;
        }
    }

    /** `403 needs_transcription_key`: on Free, without a key of their own, a transcript cannot start. */
    private function assertKey(CaptureSession $session): void
    {
        if ($this->coordinator->plan($session, $this->platformTranscription((int) $session->workspace_id, (int) $session->user_id))['own_key']
            && app(TranscriptionKeys::class)->keyFor((int) $session->user_id) === null) {
            throw new ApiError(403, TranscriptErrorCode::NeedsTranscriptionKey->value, TranscriptErrorCode::plainWords(TranscriptErrorCode::NeedsTranscriptionKey));
        }
    }

    /** `403 budget_exceeded` with `minutes_needed` and `minutes_left` when this month's limit cannot cover [$needed]. */
    private function assertBudget(int $workspaceId, int $userId, int $needed): void
    {
        // Worked out again under the lock: what other starts spent a moment ago counts.
        $left = $this->minutesLeft($workspaceId, $userId, fresh: true);
        if ($left !== null && $needed > $left) {
            throw new ApiError(403, 'budget_exceeded', PlanLimits::MINUTES_MESSAGE, ['limit' => 'monthly_minutes', 'minutes_needed' => $needed, 'minutes_left' => $left]);
        }
    }

    private static function notAudioOnly(): ApiError
    {
        return new ApiError(409, 'not_audio_only', 'This recording is not waiting to be transcribed (it is transcribed already or still uploading).');
    }
}
