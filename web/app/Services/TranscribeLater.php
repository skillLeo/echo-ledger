<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Scopes\OwnerScope;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Entitlements\TranscriptionKeys;
use App\Services\Usage\UsageLedger;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Collection;

/**
 * Audio only and "Transcribe later" (contract 1.8.0-e): what a transcript of an audio-only recording would use and
 * the minutes left, and starting it, one or many, with the usual routing (FinalTranscriptCoordinator). Minutes are
 * the plan's minutes: the recording's length rounded up to the minute, times the channels the engine bills; nothing
 * when it would run on an on-device engine or the person's own key, which count for no minutes.
 */
final class TranscribeLater
{
    public const MAX_BULK = 50;

    public function __construct(private readonly FinalTranscriptCoordinator $coordinator, private readonly PlanLimits $limits) {}

    /** The plan minutes a transcript of [$session] would use if it started now. */
    public function minutes(CaptureSession $session): int
    {
        ['provider' => $provider, 'own_key' => $ownKey] = $this->coordinator->plan($session);
        if ($ownKey || ! UsageLedger::isBillable($provider)) {
            return 0;
        }
        $primary = $session->relationLoaded('recordings')
            ? $session->recordings->first(fn ($r) => $r->role === RecordingRole::Primary)
            : $session->recordings()->where('role', RecordingRole::Primary->value)->first();
        $ms = (int) ($primary->duration_ms ?? $session->duration_ms ?? 0);

        return (int) ceil(max(0, $ms) / 60000) * UsageLedger::estimatedChannels($provider, (int) ($primary->channels ?? 1));
    }

    /** The minutes left this month for this person in the workspace; null = no limit. Read once a request. */
    public function minutesLeft(int $workspaceId, int $userId): ?int
    {
        $key = 'el.minutes-left.'.$workspaceId.'.'.$userId;
        if (request()->attributes->has($key)) {
            /** @var int|null */
            return request()->attributes->get($key);
        }
        $limit = $this->limits->limit($workspaceId, 'monthly_minutes', $userId);
        $left = $limit === null ? null : max(0, (int) floor((float) $limit - $this->limits->minutesUsed($workspaceId)));
        request()->attributes->set($key, $left);

        return $left;
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
        $this->assertBudget((int) $session->workspace_id, (int) $session->user_id, $needed);

        return $this->claimAndStart($session) ?? throw self::notAudioOnly();
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
        if ($eligible->isNotEmpty()) {
            $this->assertKey($eligible->first());
            $this->assertBudget($workspaceId, $userId, $minutes);
        }
        $started = [];
        foreach ($eligible as $session) {
            if ($this->claimAndStart($session) !== null) {
                $started[] = $session->id;
            } else {
                // Another request started it a moment ago.
                $skipped[] = ['id' => $session->id, 'code' => 'not_audio_only'];
            }
        }

        return ['started' => $started, 'skipped' => $skipped, 'minutes' => $minutes];
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
     * transcript. Null when it was no longer audio only.
     */
    private function claimAndStart(CaptureSession $session): ?Transcript
    {
        $claimed = CaptureSession::query()->whereKey($session->id)->where('status', SessionStatus::AudioOnly->value)
            ->update(['status' => SessionStatus::Processing->value, 'transcribe' => true, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }
        $session->refresh();

        return $this->coordinator->start($session, $session->finalTranscript()->first());
    }

    /** `403 needs_transcription_key`: on Free, without a key of their own, a transcript cannot start. */
    private function assertKey(CaptureSession $session): void
    {
        if ($this->coordinator->plan($session)['own_key'] && app(TranscriptionKeys::class)->keyFor((int) $session->user_id) === null) {
            throw new ApiError(403, TranscriptErrorCode::NeedsTranscriptionKey->value, TranscriptErrorCode::plainWords(TranscriptErrorCode::NeedsTranscriptionKey));
        }
    }

    /** `403 budget_exceeded` with `minutes_needed` and `minutes_left` when this month's limit cannot cover [$needed]. */
    private function assertBudget(int $workspaceId, int $userId, int $needed): void
    {
        $left = $this->minutesLeft($workspaceId, $userId);
        if ($left !== null && $needed > $left) {
            throw new ApiError(403, 'budget_exceeded', PlanLimits::MINUTES_MESSAGE, ['limit' => 'monthly_minutes', 'minutes_needed' => $needed, 'minutes_left' => $left]);
        }
    }

    private static function notAudioOnly(): ApiError
    {
        return new ApiError(409, 'not_audio_only', 'This recording is not waiting to be transcribed (it is transcribed already or still uploading).');
    }
}
