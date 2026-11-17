<?php

declare(strict_types=1);

namespace App\Services\CallRules;

use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\RetentionPolicy;
use Illuminate\Support\Carbon;

/**
 * How long audio nobody transcribed is kept (contract 2.1.0-a): the person's allowance (an admin override, else the
 * plan's `audio_retention_days`; null = Never), shortened by their own choice. And each recording's retention state.
 */
final class AudioRetention
{
    /** The choices offered, in days; "Never" is null. */
    public const OPTIONS = [7, 14, 30, 60, 90, 180, 365];

    /** Days before removal when Home starts saying so. */
    public const WARN_DAYS = 3;

    /** @var array<int, bool>|null workspace id => shared, for one request */
    private ?array $shared = null;

    /** The allowance in days, null = Never. */
    public function allowance(int $workspaceId, ?int $userId): ?int
    {
        // Asked of the request's own Entitlements: this service lives for the request, Entitlements' plans may change.
        $limit = app(Entitlements::class)->limit($workspaceId, $userId, 'audio_retention_days');

        return $limit === null ? null : max(1, (int) $limit);
    }

    /** The retention in force: the shorter of the allowance and the person's own choice; null = Never. */
    public function days(int $workspaceId, ?User $user): ?int
    {
        $allowance = $this->allowance($workspaceId, $user?->id);
        $choice = $user?->audio_retention_choice;
        if ($choice === null || $choice === 'plan' || $choice === 'never') {
            return $allowance;
        }
        $days = (int) $choice;

        return $allowance === null ? $days : min($days, $allowance);
    }

    /** Saves the person's own choice: "plan", one of the options, or "never" (only where the allowance is Never). */
    public function choose(User $user, int $workspaceId, string|int $choice): void
    {
        $allowance = $this->allowance($workspaceId, $user->id);
        if ($choice === 'never') {
            if ($allowance !== null) {
                throw new ApiError(422, 'never_not_allowed', 'Your plan cleans up audio after '.$allowance.' days.');
            }
        } elseif ($choice !== 'plan') {
            $days = (int) $choice;
            if (! in_array($days, self::OPTIONS, true)) {
                throw new ApiError(422, 'invalid_choice', 'That is not one of the choices.');
            }
            if ($allowance !== null && $days > $allowance) {
                throw new ApiError(422, 'above_plan', 'Your plan keeps audio for '.$allowance.' days at most.');
            }
        }
        $user->forceFill(['audio_retention_choice' => (string) $choice])->save();
    }

    /** @return array<string, mixed> GET /api/account/privacy's audio_retention */
    public function describe(User $user, int $workspaceId): array
    {
        $allowance = $this->allowance($workspaceId, $user->id);
        $choice = $user->audio_retention_choice ?? 'plan';

        return [
            'choice' => is_numeric($choice) ? (int) $choice : $choice,
            'days' => $this->days($workspaceId, $user),
            'allowance_days' => $allowance,
            'options' => array_values(array_filter(self::OPTIONS, fn (int $d): bool => $allowance === null || $d <= $allowance)),
            'never_allowed' => $allowance === null,
        ];
    }

    /**
     * One recording's retention state.
     *
     * @return array{audio_only: bool, days_left: int|null, delete_on: string|null, starred: bool, workspace: bool, kept: bool, protected: bool}
     */
    public function state(CaptureSession $session, ?User $user = null, ?bool $transcribed = null): array
    {
        $starred = (bool) $session->pinned;
        $kept = (bool) $session->keep_forever;
        $workspace = $this->isShared((int) $session->workspace_id);
        // Only a recording kept as audio needs its transcript looked up (a library page has it loaded already).
        $transcribed ??= $session->choice === 'keep_audio' ? $this->transcribed($session) : null;
        $audioOnly = $session->choice === 'keep_audio' && $transcribed !== true;
        $protected = $starred || $kept || $workspace || $transcribed === true;
        $deleteOn = null;
        if ($audioOnly && ! $protected) {
            $days = $this->days((int) $session->workspace_id, $user ?? $session->user);
            $deleteOn = $days === null ? null : self::removalDay($session->choice_at ?? $session->created_at, $days);
        }

        return [
            'audio_only' => $audioOnly,
            'days_left' => $deleteOn === null ? null : max(0, (int) round(now()->startOfDay()->diffInDays($deleteOn, false)) - 1),
            'delete_on' => $deleteOn?->toAtomString(),
            'starred' => $starred,
            'workspace' => $workspace,
            'kept' => $kept,
            'protected' => $protected,
        ];
    }

    public function isShared(int $workspaceId): bool
    {
        $this->shared ??= RetentionPolicy::sharedWorkspaces()->pluck('workspace_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();

        return isset($this->shared[$workspaceId]);
    }

    /** The day (start of day) a recording kept as audio on [$at] is removed, for [$days] days of retention. */
    public static function removalDay(Carbon $at, int $days): Carbon
    {
        return $at->copy()->addDays($days)->startOfDay()->addDay();
    }

    private function transcribed(CaptureSession $session): bool
    {
        if ($session->relationLoaded('finalTranscript')) {
            return $session->finalTranscript?->status === TranscriptStatus::Completed;
        }

        return $session->finalTranscript()->where('status', TranscriptStatus::Completed->value)->exists();
    }
}
