<?php

declare(strict_types=1);

namespace App\Services\CallRules;

use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Recording;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Transcribe many (contract 2.1.0-a): the minutes first, then all or nothing. And the storage line of Account & plan.
 */
final class BatchTranscribe
{
    public function __construct(private readonly PlanLimits $limits, private readonly SessionChoice $choices) {}

    /**
     * The person's recordings kept as audio among [$ids] in the device's workspace.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CaptureSession>
     */
    public function audioOnly(Device $device, array $ids): Collection
    {
        return self::audioOnlyQuery((int) $device->workspace_id)->where('user_id', $device->user_id)->whereIn('id', $ids)->get();
    }

    /** @return Builder<CaptureSession> recordings of the workspace kept as audio with no finished or running transcript */
    public static function audioOnlyQuery(int $workspaceId): Builder
    {
        return CaptureSession::query()->where('workspace_id', $workspaceId)->where('choice', 'keep_audio')
            ->whereDoesntHave('finalTranscript', fn (Builder $t) => $t->where('status', '!=', TranscriptStatus::Failed->value));
    }

    /**
     * @param  list<string>  $ids
     * @return array{count: int, minutes: float, minutes_left: float|null, enough: bool, items: list<array{id: string, minutes: float}>}
     */
    public function estimate(Device $device, array $ids): array
    {
        $items = $this->audioOnly($device, $ids)->map(fn (CaptureSession $s): array => ['id' => $s->id, 'minutes' => self::minutes($s)])->values()->all();
        $minutes = round(array_sum(array_column($items, 'minutes')), 1);
        $left = $this->minutesLeft((int) $device->workspace_id, (int) $device->user_id);

        return ['count' => count($items), 'minutes' => $minutes, 'minutes_left' => $left, 'enough' => $left === null || $minutes <= $left, 'items' => $items];
    }

    /** @param list<string> $ids */
    public function start(Device $device, array $ids, float $maxMinutes): int
    {
        $estimate = $this->estimate($device, $ids);
        if ($estimate['minutes'] > $maxMinutes + 0.05) {
            throw new ApiError(409, 'estimate_changed', 'These recordings now need '.$estimate['minutes'].' minutes.', ['minutes' => $estimate['minutes']]);
        }
        if (! $estimate['enough']) {
            throw new ApiError(403, 'limit_reached', 'Not enough minutes left this month for these recordings.', ['limit' => 'monthly_minutes']);
        }
        $started = 0;
        // One at a time: each start reads its recording's project and engine on its own.
        foreach (array_column($estimate['items'], 'id') as $id) {
            $session = CaptureSession::query()->find($id);
            if ($session instanceof CaptureSession) {
                $this->choices->choose($session, 'transcribe', 'user');
                $started++;
            }
        }

        return $started;
    }

    /** @return array{used_bytes: int, audio_only_bytes: int, limit_bytes: int|null} */
    public function storage(Device $device): array
    {
        $workspaceId = (int) $device->workspace_id;
        $limit = $this->limits->limit($workspaceId, 'storage_bytes', (int) $device->user_id);

        return [
            'used_bytes' => $this->limits->storageUsed($workspaceId),
            'audio_only_bytes' => self::audioOnlyBytes($workspaceId, (int) $device->user_id),
            'limit_bytes' => $limit === null ? null : (int) $limit,
        ];
    }

    public static function audioOnlyBytes(int $workspaceId, ?int $userId = null): int
    {
        $sessions = self::audioOnlyQuery($workspaceId)->when($userId !== null, fn (Builder $q) => $q->where('user_id', $userId))->select('id');

        return (int) Recording::query()->whereNotNull('stored_path')->whereIn('capture_session_id', $sessions)->sum('stored_size_bytes');
    }

    private function minutesLeft(int $workspaceId, int $userId): ?float
    {
        $limit = $this->limits->limit($workspaceId, 'monthly_minutes', $userId);

        return $limit === null ? null : max(0.0, round((float) $limit - $this->limits->minutesUsed($workspaceId), 1));
    }

    private static function minutes(CaptureSession $session): float
    {
        return round(((int) $session->duration_ms) / 60000, 1);
    }
}
