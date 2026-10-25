<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Retention controls (plan B17). Recordings older than their retention (the client's own, else the general setting)
 * go to the undo bin unless they are kept; the bin erases audio and text 48 hours after a delete. Runs hourly.
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

        return $moved;
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
        return CaptureSession::onlyTrashed()->where('workspace_id', $workspaceId)->whereNull('media_erased_at')
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
     * The storage meter: what the recordings take and how full the server disk is.
     *
     * @return array{recordings_bytes: int, recordings_count: int, disk_free_bytes: int|null, disk_total_bytes: int|null}
     */
    public function storage(?int $workspaceId = null): array
    {
        $query = Recording::query()->whereNotNull('stored_path');
        if ($workspaceId !== null) {
            $query->whereHas('captureSession', fn (Builder $q) => $q->withTrashed()->where('workspace_id', $workspaceId));
        }
        $root = Storage::disk('recordings')->path('');
        $free = is_dir($root) ? @disk_free_space($root) : false;
        $total = is_dir($root) ? @disk_total_space($root) : false;

        return [
            'recordings_bytes' => (int) $query->sum('stored_size_bytes'),
            'recordings_count' => $query->count(),
            'disk_free_bytes' => $free === false ? null : (int) $free,
            'disk_total_bytes' => $total === false ? null : (int) $total,
        ];
    }
}
