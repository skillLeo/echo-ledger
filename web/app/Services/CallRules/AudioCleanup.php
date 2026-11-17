<?php

declare(strict_types=1);

namespace App\Services\CallRules;

use App\Enums\SessionStatus;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Audio clean-up (contract 2.1.0-a), once a day: recordings kept as audio only, not starred, not kept, not in a shared
 * workspace, past the person's retention go to Trash, which erases them for good after `trash.keep_hours` (audio,
 * waveforms, pictures and the engines' copies, RetentionPolicy::emptyBin). Transcribed, starred, kept and workspace
 * recordings are never touched. "Never" leaves everything.
 */
final class AudioCleanup
{
    /** True while the clean-up deletes, so the delete is counted as cleaned up and not as the person's. */
    public static bool $running = false;

    public function __construct(private readonly AudioRetention $retention) {}

    /** @return Builder<CaptureSession> what the clean-up may remove, whatever its age */
    public static function candidates(): Builder
    {
        return CaptureSession::acrossPeople()->where('choice', 'keep_audio')->where('pinned', false)->where('keep_forever', false)
            ->where('status', '!=', SessionStatus::Recording->value)
            ->whereNotIn('workspace_id', RetentionPolicy::sharedWorkspaces())
            ->whereDoesntHave('finalTranscript', fn (Builder $t) => $t->where('status', '!=', TranscriptStatus::Failed->value));
    }

    /** Moves what is past its retention to Trash; returns how many. */
    public function run(): int
    {
        $moved = 0;
        $users = [];
        self::$running = true;
        try {
            self::candidates()->with('user')->chunkById(200, function (Collection $sessions) use (&$moved, &$users): void {
                foreach ($sessions as $session) {
                    $user = $users[$session->user_id] ??= $session->user;
                    $on = $this->removalDay($session, $user);
                    if ($on !== null && $on->lessThanOrEqualTo(now())) {
                        $session->delete();
                        ChoiceLog::record($session, ChoiceLog::CLEANED, 'cleanup');
                        $moved++;
                    }
                }
            });
        } finally {
            self::$running = false;
        }

        return $moved;
    }

    /**
     * What will go within the warning window (3 days) for one person in one workspace, and the earliest day.
     *
     * @return array{count: int, on: string|null}
     */
    public function soon(int $workspaceId, User $user): array
    {
        $limit = now()->addDays(AudioRetention::WARN_DAYS);
        $count = 0;
        $first = null;
        foreach (self::candidates()->where('workspace_id', $workspaceId)->where('user_id', $user->id)->get(['id', 'workspace_id', 'user_id', 'choice_at', 'created_at']) as $session) {
            $on = $this->removalDay($session, $user);
            if ($on !== null && $on->lessThanOrEqualTo($limit)) {
                $count++;
                $first = $first === null || $on->lessThan($first) ? $on : $first;
            }
        }

        return ['count' => $count, 'on' => $first?->toAtomString()];
    }

    private function removalDay(CaptureSession $session, ?User $user): ?Carbon
    {
        $days = $this->retention->days((int) $session->workspace_id, $user);

        return $days === null ? null : AudioRetention::removalDay($session->choice_at ?? $session->created_at, $days);
    }
}
