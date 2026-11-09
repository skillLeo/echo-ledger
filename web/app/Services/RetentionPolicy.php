<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\Entitlements;
use App\Services\Storage\RecordingFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention controls (plan B17). Recordings older than their retention (the client's own, else the general setting,
 * and since v1.5 never longer than their workspace plan's days) go to the undo bin unless they are kept; the bin erases
 * audio and text 48 hours after a delete. Runs hourly.
 */
final class RetentionPolicy
{
    /** Retention choices offered in the console and on clients; any 1-365 is accepted as a custom value. */
    public const PRESETS = [7, 14, 30];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function daysFor(?Client $client): int
    {
        return $client?->retention_days !== null ? max(1, min(365, $client->retention_days)) : $this->settings->retentionDays();
    }

    /**
     * The general retention of a workspace: the setting, or its plan's days when those are fewer (since v1.5); since
     * v1.6 the plan that applies now, and its owner's override.
     */
    public function daysForWorkspace(int $workspaceId): int
    {
        $plan = app(Entitlements::class)->limit($workspaceId, null, 'retention_days');
        $plan = $plan === null ? null : (int) $plan;

        return $plan !== null ? min($plan, $this->settings->retentionDays()) : $this->settings->retentionDays();
    }

    /**
     * Moves recordings past their retention to the bin; kept recordings and those still recording stay. Returns how
     * many were moved.
     */
    public function applyRetention(): int
    {
        $moved = 0;
        $custom = Client::query()->whereNotNull('retention_days')->get(['id', 'retention_days']);
        foreach ($custom as $client) {
            $moved += $this->binExpired(CaptureSession::query()->where('client_id', $client->id), $this->daysFor($client));
        }
        $moved += $this->binExpired(
            CaptureSession::query()->where(fn (Builder $q) => $q->whereNull('client_id')->orWhereNotIn('client_id', $custom->modelKeys())),
            $this->settings->retentionDays(),
        );
        // Since v1.5 each plan's days too: whichever comes first moves a recording, a client's longer days included. Since
        // v1.6 only recordings made since the plan's retention was set (`retention_from`; none = every one, as before),
        // and a workspace whose owner has their own retention (the super admin's override) keeps that instead.
        $own = $this->ownRetention();
        foreach (Plan::query()->whereNotNull('retention_days')->get(['id', 'retention_days', 'retention_from']) as $plan) {
            $query = CaptureSession::query()->whereIn('workspace_id', Workspace::query()->where('plan_id', $plan->id)->whereNotIn('id', array_keys($own))->select('id'));
            $moved += $this->binExpired($plan->retention_from !== null ? $query->where('created_at', '>=', $plan->retention_from) : $query, max(1, (int) $plan->retention_days));
        }
        foreach ($own as $workspaceId => $days) {
            if ($days !== null) {
                $from = Workspace::query()->with('plan')->find($workspaceId)?->plan?->retention_from;
                $query = CaptureSession::query()->where('workspace_id', $workspaceId);
                $moved += $this->binExpired($from !== null ? $query->where('created_at', '>=', $from) : $query, max(1, $days));
            }
        }

        return $moved;
    }

    /** @return array<int, int|null> workspace id => its owner's own retention days (null = none) */
    private function ownRetention(): array
    {
        $own = [];
        User::query()->whereNotNull('entitlement_overrides')->get(['id', 'entitlement_overrides'])->each(function (User $user) use (&$own): void {
            $limits = $user->entitlement_overrides['limits'] ?? null;
            if (! is_array($limits) || ! array_key_exists('retention_days', $limits)) {
                return;
            }
            $days = is_numeric($limits['retention_days']) ? (int) $limits['retention_days'] : null;
            foreach (DB::table('workspace_user')->where('user_id', $user->id)->where('role', 'owner')->pluck('workspace_id') as $workspaceId) {
                $own[(int) $workspaceId] = $days;
            }
        });

        return $own;
    }

    /** @param Builder<CaptureSession> $query */
    private function binExpired(Builder $query, int $days): int
    {
        $moved = 0;
        $query->where('keep_forever', false)->where('status', '!=', SessionStatus::Recording->value)
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(100, function (Collection $sessions) use (&$moved): void {
                foreach ($sessions as $session) {
                    $session->delete();
                    $moved++;
                }
            });

        return $moved;
    }

    /** Erases the audio and text of recordings deleted more than 48 hours ago. Returns how many. */
    public function emptyBin(): int
    {
        $erased = 0;
        CaptureSession::onlyTrashed()->whereNull('media_erased_at')->where('deleted_at', '<=', now()->subHours(CaptureSession::BIN_HOURS))
            ->chunkById(100, function (Collection $sessions) use (&$erased): void {
                foreach ($sessions as $session) {
                    $session->purgeMedia();
                    $erased++;
                }
            });

        return $erased;
    }

    /**
     * The workspace's undo bin, most recently deleted first.
     *
     * @return Collection<int, CaptureSession>
     */
    public function bin(int $workspaceId): Collection
    {
        return CaptureSession::onlyTrashed()->where('workspace_id', $workspaceId)->ofPerson()->whereNull('media_erased_at')
            ->where('deleted_at', '>', now()->subHours(CaptureSession::BIN_HOURS))->with('client')->orderByDesc('deleted_at')->get();
    }

    /** Takes a recording out of the bin; `410 restore_expired` once its 48 hours are over. */
    public function restore(CaptureSession $session): CaptureSession
    {
        if (! $session->restorable()) {
            throw new ApiError(410, 'restore_expired', 'This recording can no longer be restored.');
        }
        $session->restore();

        return $session->refresh();
    }

    /** Erases a binned recording now instead of after 48 hours. */
    public function deleteNow(CaptureSession $session): void
    {
        if ($session->trashed() && $session->media_erased_at === null) {
            $session->purgeMedia();
        }
    }

    /**
     * The storage meter: what the person's recordings in the workspace take, with their screen moments' pictures and
     * thumbnails since v1.5 (every person's on a server-wide read), and how full the server disk is.
     *
     * @return array{recordings_bytes: int, recordings_count: int, disk_free_bytes: int|null, disk_total_bytes: int|null}
     */
    public function storage(?int $workspaceId = null): array
    {
        $query = Recording::query()->whereNotNull('stored_path');
        $screens = Screen::query()->withoutGlobalScope(WorkspaceScope::class);
        if ($workspaceId !== null) {
            $query->whereHas('captureSession', fn (Builder $q) => $q->withTrashed()->where('workspace_id', $workspaceId)->ofPerson());
            $screens->whereIn('capture_session_id', CaptureSession::withTrashed()->where('workspace_id', $workspaceId)->ofPerson()->select('id'));
        }
        $space = app(RecordingFiles::class)->diskSpace();

        return [
            'recordings_bytes' => (int) $query->sum('stored_size_bytes') + (int) $screens->sum(DB::raw('COALESCE(stored_bytes, bytes)')),
            'recordings_count' => $query->count(),
            'disk_free_bytes' => $space['free'],
            'disk_total_bytes' => $space['total'],
        ];
    }
}
