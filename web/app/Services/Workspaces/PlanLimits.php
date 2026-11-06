<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Models\UsageLedgerEntry;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\SettingsRepository;
use App\Services\Usage\UsagePeriod;
use Illuminate\Support\Facades\DB;

/**
 * The one place plan limits are checked (B20, v1.5): minutes per month (STT token and final transcript), storage
 * (upload slot, console voice note, request links, Save to my library, screen moments), paired devices (pairing code
 * and redeem), seats (invitations), features and AI credits. Counts always filter by the explicit workspace id,
 * whatever workspace the request acts for, and cover every person of it. `null` limits never block.
 */
final class PlanLimits
{
    public const MINUTES_MESSAGE = 'Monthly limit reached — raise it in Settings';

    public const STORAGE_MESSAGE = 'Storage limit reached — raise it in Settings';

    public const DEVICES_MESSAGE = 'Device limit reached — raise it in Settings';

    public const SEATS_MESSAGE = 'Every seat of this workspace\'s plan is taken — remove someone or move to a bigger plan';

    /** What `403 feature_not_in_plan` says for each feature. */
    public const FEATURE_MESSAGES = [
        'live_translate' => 'Live Translate is not in your plan',
        'screen_moments' => 'Screen moments are not in your plan',
        'ai_notes' => 'AI notes are not in your plan',
        'connector' => 'The Claude and ChatGPT connector is not in your plan',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function plan(int $workspaceId): Plan
    {
        return Workspace::query()->find($workspaceId)->plan ?? Plan::personal();
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

    /**
     * Stored recordings of the workspace, every person's and the undo bin's (it still takes the space), and since v1.5
     * their screen moments: the encrypted picture and thumbnail, else the picture's size for a screen stored before.
     */
    public function storageUsed(int $workspaceId): int
    {
        $sessions = CaptureSession::acrossPeople()->withTrashed()->where('workspace_id', $workspaceId)->select('id');
        $recordings = (int) Recording::query()->whereNotNull('stored_path')->whereIn('capture_session_id', $sessions)->sum('stored_size_bytes');
        $screens = (int) Screen::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('capture_session_id', $sessions)
            ->sum(DB::raw('COALESCE(stored_bytes, bytes)'));

        return $recordings + $screens;
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

    /** People in the workspace and invitations still open (since v1.5): each takes a seat until it is used or ends. */
    public function seatsUsed(int $workspaceId, ?int $exceptInvitation = null): int
    {
        $members = DB::table('workspace_user')->where('workspace_id', $workspaceId)->count();
        $open = WorkspaceInvitation::query()->where('workspace_id', $workspaceId)->whereNull('accepted_at')
            ->where('expires_at', '>', now())->when($exceptInvitation !== null, fn ($q) => $q->whereKeyNot($exceptInvitation))->count();

        return $members + $open;
    }

    /**
     * `403 seat_limit_reached` when one more person would pass the plan's seats: a new invitation, or accepting one
     * (which then no longer counts itself).
     */
    public function assertSeat(int $workspaceId, ?int $acceptingInvitation = null): void
    {
        $seats = $this->plan($workspaceId)->seats;
        if ($seats !== null && $this->seatsUsed($workspaceId, $acceptingInvitation) + 1 > $seats) {
            throw new ApiError(403, 'seat_limit_reached', self::SEATS_MESSAGE);
        }
    }

    public function allows(int $workspaceId, string $feature): bool
    {
        return $this->plan($workspaceId)->allows($feature);
    }

    /** `403 feature_not_in_plan` with `feature` when the workspace's plan leaves it out (since v1.5). */
    public function assertFeature(int $workspaceId, string $feature): void
    {
        if (! $this->allows($workspaceId, $feature)) {
            throw new ApiError(403, 'feature_not_in_plan', self::FEATURE_MESSAGES[$feature] ?? 'This is not in your plan', ['feature' => $feature]);
        }
    }

    /** The plan's AI credits per month in US dollars, or null when it sets none. */
    public function aiCredits(int $workspaceId): ?float
    {
        return $this->plan($workspaceId)->aiCredits();
    }

    /** The plan's retention in days, or null when it sets none (the console's retention applies alone). */
    public function retentionDays(int $workspaceId): ?int
    {
        return $this->plan($workspaceId)->retention_days;
    }

    /**
     * Meters for the console: used and limit of each plan dimension.
     *
     * @return array{plan: string, minutes: array{used: float, limit: int|null}, storage: array{used: int, limit: int|null}, devices: array{used: int, limit: int|null}, seats: array{used: int, limit: int|null}}
     */
    public function summary(int $workspaceId): array
    {
        $plan = $this->plan($workspaceId);

        return [
            'plan' => $plan->name,
            'minutes' => ['used' => $this->minutesUsed($workspaceId), 'limit' => $plan->monthly_minutes],
            'storage' => ['used' => $this->storageUsed($workspaceId), 'limit' => $plan->storage_bytes],
            'devices' => ['used' => $this->devicesUsed($workspaceId), 'limit' => $plan->max_devices],
            'seats' => ['used' => $this->seatsUsed($workspaceId), 'limit' => $plan->seats],
        ];
    }
}
