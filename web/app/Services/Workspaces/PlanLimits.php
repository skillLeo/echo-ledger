<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\SttProviderKey;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Models\UsageLedgerEntry;
use App\Models\WorkspaceInvitation;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Entitlements\Entitlements;
use App\Services\SettingsRepository;
use App\Services\Usage\UsagePeriod;
use Illuminate\Support\Facades\DB;

/**
 * The one place plan limits are checked (B20, v1.5): minutes per month (STT token and final transcript), storage
 * (upload slot, console voice note, request links, Save to my library, screen moments), paired devices (pairing code
 * and redeem), seats (invitations), features and AI credits. Counts always filter by the explicit workspace id,
 * whatever workspace the request acts for, and cover every person of it. `null` limits never block.
 *
 * Since v1.6 (P1, docs/API.md "Entitlements") the plan is the one that applies now (App\Services\Entitlements) and a
 * person's own overrides count when the person is known (`$userId`); devices are counted per person; recordings a day
 * and the longest recording are limits too. Every limit error carries `limit` (its key): apps of v1.6 and later get
 * `403 limit_reached` (ApiExceptionRenderer), older ones the code they know.
 */
final class PlanLimits
{
    public const MINUTES_MESSAGE = 'Monthly limit reached — raise it in Settings';

    public const STORAGE_MESSAGE = 'Storage limit reached — raise it in Settings';

    public const DEVICES_MESSAGE = 'Device limit reached — raise it in Settings';

    public const SEATS_MESSAGE = 'Every seat of this workspace\'s plan is taken — remove someone or move to a bigger plan';

    public const UPLOADS_MESSAGE = 'You reached today\'s recordings for your plan. The recording stays on your device; send it tomorrow or upgrade.';

    /** Codes v1.5 apps know for a limit, and the limit each stands for; v1.6 apps get `limit_reached` instead. */
    public const LEGACY_CODES = ['budget_exceeded', 'storage_limit_reached', 'device_limit_reached'];

    /** What `403 feature_not_in_plan` says for each feature. */
    public const FEATURE_MESSAGES = Entitlements::LOCKED;

    public function __construct(private readonly SettingsRepository $settings, private readonly Entitlements $entitlements) {}

    /** The plan that applies now (past its end date, the default plan). */
    public function plan(int $workspaceId): Plan
    {
        return $this->entitlements->plan($workspaceId);
    }

    /** A limit of the registry for this person (their overrides) in this workspace; null = no limit. */
    public function limit(int $workspaceId, string $key, ?int $userId = null): int|float|null
    {
        return $this->entitlements->limit($workspaceId, $userId, $key);
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

    /**
     * The devices that count against the limit: since v1.6 a person's own in the workspace ([$userId]), the whole
     * workspace's when no person is named. Signed-out devices and those 90 days without a call (P3) do not count.
     */
    public function devicesUsed(int $workspaceId, ?int $userId = null): int
    {
        return Device::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->counting()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))->count();
    }

    /** Recordings the person started in the workspace in the last 24 hours (since v1.6). */
    public function uploadsToday(int $workspaceId, int $userId): int
    {
        return CaptureSession::acrossPeople()->withTrashed()->where('workspace_id', $workspaceId)->where('user_id', $userId)
            ->where('created_at', '>', now()->subDay())->count();
    }

    /** US dollars of AI work (client notes, memory, Ask) this month. */
    public function aiSpent(int $workspaceId): float
    {
        return (float) app(ClientWorkspace::class)->budget($workspaceId)['spent_usd'];
    }

    public function minutesReached(int $workspaceId, ?int $userId = null): bool
    {
        $limit = $this->limit($workspaceId, 'monthly_minutes', $userId);

        return $limit !== null && $this->minutesUsed($workspaceId) >= $limit;
    }

    /** `403 budget_exceeded`: the code devices already handle for the monthly limit, so older builds behave. */
    public function assertMinutes(int $workspaceId, ?int $userId = null): void
    {
        if ($this->minutesReached($workspaceId, $userId)) {
            throw new ApiError(403, 'budget_exceeded', self::MINUTES_MESSAGE, ['limit' => 'monthly_minutes']);
        }
    }

    public function assertStorage(int $workspaceId, int $incomingBytes, ?int $userId = null): void
    {
        $limit = $this->limit($workspaceId, 'storage_bytes', $userId);
        if ($limit !== null && $this->storageUsed($workspaceId) + max(0, $incomingBytes) > $limit) {
            throw new ApiError(403, 'storage_limit_reached', self::STORAGE_MESSAGE, ['limit' => 'storage_bytes']);
        }
    }

    /** Since v1.6 the person's own devices count: [$userId] is the person signing a device in. */
    public function assertDevices(int $workspaceId, ?int $userId = null): void
    {
        $limit = $this->limit($workspaceId, 'devices', $userId);
        if ($limit !== null && $this->devicesUsed($workspaceId, $userId) >= $limit) {
            throw new ApiError(403, 'device_limit_reached', $userId !== null ? self::devicesMessage((int) $limit) : self::DEVICES_MESSAGE, ['limit' => 'devices']);
        }
    }

    /** "Your plan signs in 2 devices": what the apps and the console say at the device limit (since v1.6). */
    public static function devicesMessage(int $limit): string
    {
        return $limit === 1
            ? 'Your plan signs in 1 device. Sign out the other one (Account → Devices), or upgrade.'
            : "Your plan signs in {$limit} devices. Sign one out (Account → Devices), or upgrade.";
    }

    /** Recordings a day (since v1.6): one more new recording of the person. Old apps get storage_limit_reached. */
    public function assertUploadsToday(int $workspaceId, int $userId): void
    {
        $limit = $this->limit($workspaceId, 'uploads_per_day', $userId);
        if ($limit !== null && $this->uploadsToday($workspaceId, $userId) >= $limit) {
            throw new ApiError(403, 'storage_limit_reached', self::UPLOADS_MESSAGE, ['limit' => 'uploads_per_day']);
        }
    }

    /** The longest recording (since v1.6): [$durationMs] of a recording about to be stored. */
    public function assertFileMinutes(int $workspaceId, ?int $durationMs, ?int $userId = null): void
    {
        $limit = $this->limit($workspaceId, 'max_file_minutes', $userId);
        if ($limit !== null && $durationMs !== null && $durationMs > (int) $limit * 60000) {
            throw new ApiError(403, 'storage_limit_reached', "This recording is longer than your plan allows ({$limit} minutes). It stays on your device; upgrade to send it.", ['limit' => 'max_file_minutes']);
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
    public function assertSeat(int $workspaceId, ?int $acceptingInvitation = null, ?int $userId = null): void
    {
        $seats = $this->limit($workspaceId, 'seats', $userId);
        if ($seats !== null && $this->seatsUsed($workspaceId, $acceptingInvitation) + 1 > $seats) {
            throw new ApiError(403, 'seat_limit_reached', self::SEATS_MESSAGE, ['limit' => 'seats']);
        }
    }

    public function allows(int $workspaceId, string $feature, ?int $userId = null): bool
    {
        return $this->entitlements->allows($workspaceId, $userId, $feature);
    }

    /** `403 feature_not_in_plan` with `feature` (and since v1.6 `lowest_plan`) when the plan leaves it out (since v1.5). */
    public function assertFeature(int $workspaceId, string $feature, ?int $userId = null): void
    {
        $this->entitlements->assertFeature($workspaceId, $userId, $feature);
    }

    /** The plan's AI credits per month in US dollars, or null when it sets none. */
    public function aiCredits(int $workspaceId, ?int $userId = null): ?float
    {
        $value = $this->limit($workspaceId, 'ai_usd_month', $userId);

        return $value === null ? null : (float) $value;
    }

    /** The plan's retention in days, or null when it sets none (the console's retention applies alone). */
    public function retentionDays(int $workspaceId): ?int
    {
        return $this->plan($workspaceId)->retention_days;
    }

    /** Whether a final transcript may use the server's engine [$provider] for this person (since v1.6). */
    public function allowsEngine(int $workspaceId, ?int $userId, SttProviderKey $provider): bool
    {
        return $this->entitlements->allowsEngine($workspaceId, $userId, $provider);
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
