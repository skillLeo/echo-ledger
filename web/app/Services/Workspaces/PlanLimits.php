<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Models\Workspace;
use App\Services\SettingsRepository;
use App\Services\Usage\UsagePeriod;

/**
 * The one place plan limits are checked (B20): minutes per month (STT token and final transcript), storage (upload
 * slot and console voice note) and paired devices (pairing code and redeem). Counts always filter by the explicit
 * workspace id, whatever workspace the request acts for. `null` limits never block.
 */
final class PlanLimits
{
    public const MINUTES_MESSAGE = 'Monthly limit reached — raise it in Settings';

    public const STORAGE_MESSAGE = 'Storage limit reached — raise it in Settings';

    public const DEVICES_MESSAGE = 'Device limit reached — raise it in Settings';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function plan(int $workspaceId): Plan
    {
        return Workspace::query()->find($workspaceId)?->plan ?? Plan::personal();
    }

    /** Billed minutes (duration × billed channels) of billable rows this month, as the usage ledger counts them. */
    public function minutesUsed(int $workspaceId): float
    {
        $period = UsagePeriod::current($this->settings);

        return (int) UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)->where('billable', true)
            ->where('occurred_at', '>=', $period['start'])->where('occurred_at', '<', $period['end'])
            ->sum('billed_ms') / 60000;
    }

    /** Stored recordings of the workspace, the undo bin included (it still takes the space). */
    public function storageUsed(int $workspaceId): int
    {
        return (int) Recording::query()->whereNotNull('stored_path')
            ->whereIn('capture_session_id', CaptureSession::withTrashed()->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $workspaceId)->select('id'))
            ->sum('stored_size_bytes');
    }

    public function devicesUsed(int $workspaceId): int
    {
        return Device::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->count();
    }

    public function minutesReached(int $workspaceId): bool
    {
        $limit = $this->plan($workspaceId)->monthly_minutes;

        return $limit !== null && $this->minutesUsed($workspaceId) >= $limit;
    }

    /** `403 budget_exceeded`: the code devices already handle for the monthly limit, so older builds behave. */
    public function assertMinutes(int $workspaceId): void
    {
        if ($this->minutesReached($workspaceId)) {
            throw new ApiError(403, 'budget_exceeded', self::MINUTES_MESSAGE);
        }
    }

    public function assertStorage(int $workspaceId, int $incomingBytes): void
    {
        $limit = $this->plan($workspaceId)->storage_bytes;
        if ($limit !== null && $this->storageUsed($workspaceId) + max(0, $incomingBytes) > $limit) {
            throw new ApiError(403, 'storage_limit_reached', self::STORAGE_MESSAGE);
        }
    }

    public function assertDevices(int $workspaceId): void
    {
        $limit = $this->plan($workspaceId)->max_devices;
        if ($limit !== null && $this->devicesUsed($workspaceId) >= $limit) {
            throw new ApiError(403, 'device_limit_reached', self::DEVICES_MESSAGE);
        }
    }

    /**
     * Meters for the console: used and limit of each plan dimension.
     *
     * @return array{plan: string, minutes: array{used: float, limit: int|null}, storage: array{used: int, limit: int|null}, devices: array{used: int, limit: int|null}}
     */
    public function summary(int $workspaceId): array
    {
        $plan = $this->plan($workspaceId);

        return [
            'plan' => $plan->name,
            'minutes' => ['used' => $this->minutesUsed($workspaceId), 'limit' => $plan->monthly_minutes],
            'storage' => ['used' => $this->storageUsed($workspaceId), 'limit' => $plan->storage_bytes],
            'devices' => ['used' => $this->devicesUsed($workspaceId), 'limit' => $plan->max_devices],
        ];
    }
}
