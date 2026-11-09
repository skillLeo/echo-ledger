<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Enums\WorkspaceRole;
use App\Models\AdminNote;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountDeletion;
use App\Services\Accounts\AccountSessions;
use App\Services\AuditLog;
use App\Services\Entitlements\Entitlements;
use App\Services\SettingsRepository;
use App\Services\Usage\UsageBreakdown;
use App\Services\Usage\UsagePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customers on the admin panel (since v1.6, P2.2): a person with their plan, usage this month, devices, workspaces,
 * notes and the support actions. Metadata and usage only: recordings and transcripts stay private unless the person
 * turned on Support access (Profile → Support access, with an end time); every look at content during it is in the
 * audit log.
 */
final class Customers
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly SettingsRepository $settings,
        private readonly AuditLog $audit,
    ) {}

    /** The workspace a person's plan is read from: the one they own (their personal one first), else their current one. */
    public function homeWorkspaceId(User $user): ?int
    {
        $owned = DB::table('workspace_user')->where('user_id', $user->id)->where('role', WorkspaceRole::Owner->value)->orderBy('workspace_id')->value('workspace_id');
        $id = $owned ?? $user->current_workspace_id;

        return $id === null ? null : (int) $id;
    }

    public function plan(User $user): Plan
    {
        $workspace = $this->homeWorkspaceId($user);

        return $workspace === null ? Plan::defaultPlan() : $this->entitlements->plan($workspace);
    }

    /** "Active", "Suspended", "Not confirmed", "Being deleted". */
    public static function status(User $user): string
    {
        return match (true) {
            $user->isSuspended() => 'Suspended',
            $user->isBeingDeleted() => 'Being deleted',
            $user->email_verified_at === null => 'Not confirmed',
            default => 'Active',
        };
    }

    /** @var array<int, array{minutes: float, cost_usd: float, providers: list<array{label: string, minutes: float, cost_usd: float}>, plan_percent: float|null, limit_minutes: int|null}> */
    private array $months = [];

    /**
     * This month's usage of one person: minutes, US dollars per provider (the price table of Server usage) and how much
     * of the plan's minutes it is. Kept for this request.
     *
     * @return array{minutes: float, cost_usd: float, providers: list<array{label: string, minutes: float, cost_usd: float}>, plan_percent: float|null, limit_minutes: int|null}
     */
    public function month(User $user): array
    {
        if (isset($this->months[$user->id])) {
            return $this->months[$user->id];
        }
        $period = UsagePeriod::current($this->settings);
        $report = app(UsageBreakdown::class)->report(Carbon::parse($period['start']), Carbon::parse($period['end']), null, $user->id);
        $home = $this->homeWorkspaceId($user);
        $limit = $home === null ? null : $this->entitlements->limit($home, $user->id, 'monthly_minutes');
        $minutes = (float) $report['totals']['minutes'];

        return $this->months[$user->id] = [
            'minutes' => round($minutes, 1),
            'cost_usd' => round((float) $report['totals']['cost_usd'], 2),
            'providers' => array_map(fn (array $p): array => ['label' => $p['label'], 'minutes' => $p['minutes'], 'cost_usd' => $p['cost_usd']], $report['providers']),
            'plan_percent' => $limit === null ? null : ($limit > 0 ? round($minutes / $limit * 100, 1) : ($minutes > 0 ? 100.0 : 0.0)),
            'limit_minutes' => $limit === null ? null : (int) $limit,
        ];
    }

    /**
     * The customer page's numbers: this month's usage with storage, devices and recordings (how many, never what).
     *
     * @return array{minutes: float, cost_usd: float, providers: list<array{label: string, minutes: float, cost_usd: float}>, plan_percent: float|null, limit_minutes: int|null, storage_bytes: int, devices: int, recordings: int}
     */
    public function stats(User $user): array
    {
        return [
            ...$this->month($user),
            'storage_bytes' => $this->storageUsed($user),
            'devices' => Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->counting()->count(),
            'recordings' => CaptureSession::acrossPeople()->withoutGlobalScope(WorkspaceScope::class)->where('user_id', $user->id)->count(),
        ];
    }

    /** Bytes of the person's own stored recordings (the undo bin's too: they still take the space). */
    public function storageUsed(User $user): int
    {
        $sessions = CaptureSession::acrossPeople()->withoutGlobalScope(WorkspaceScope::class)->withTrashed()->where('user_id', $user->id)->select('id');

        return (int) Recording::query()->whereNotNull('stored_path')->whereIn('capture_session_id', $sessions)->sum('stored_size_bytes');
    }

    /**
     * Minutes per day for the last [$days] days in the console's time zone, oldest first.
     *
     * @return array{labels: list<string>, minutes: list<float>}
     */
    public function daily(User $user, int $days = 30): array
    {
        $zone = $this->settings->displayTimezone();
        $start = Carbon::now($zone)->startOfDay()->subDays($days - 1);
        $buckets = [];
        for ($day = $start->copy(); count($buckets) < $days; $day->addDay()) {
            $buckets[$day->format('Y-m-d')] = 0.0;
        }
        $rows = UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)->where('user_id', $user->id)->where('billable', true)
            ->where('occurred_at', '>=', $start->copy()->utc())->toBase()->select(['billed_ms', 'occurred_at'])->cursor();
        foreach ($rows as $row) {
            $key = Carbon::parse($row->occurred_at, 'UTC')->setTimezone($zone)->format('Y-m-d');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += (int) $row->billed_ms / 60000;
            }
        }

        return [
            'labels' => array_map(fn (string $d): string => Carbon::parse($d)->format('M j'), array_keys($buckets)),
            'minutes' => array_map(fn (float $m): float => round($m, 1), array_values($buckets)),
        ];
    }

    /** The person's home workspace goes on [$plan], until [$until] or not. */
    public function changePlan(User $user, Plan $plan, ?Carbon $until, User $staff): bool
    {
        $workspace = Workspace::query()->find($this->homeWorkspaceId($user));
        if (! $workspace instanceof Workspace) {
            return false;
        }
        $workspace->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => $until])->save();
        $this->entitlements->forget();
        $this->audit->record('plan_assigned', $staff->id, $user, Str::limit($user->email, 120, '')." is now on the {$plan->name} plan".($until !== null ? ' until '.$until->toDateString() : ''));

        return true;
    }

    public function suspend(User $user, bool $on, ?string $reason, User $staff): void
    {
        if ($user->is_super_admin) {
            return;
        }
        $user->forceFill(['suspended_at' => $on ? ($user->suspended_at ?? now()) : null, 'suspended_reason' => $on && filled($reason) ? Str::limit(trim((string) $reason), 500, '') : null])->save();
        $this->audit->record($on ? 'account_suspended' : 'account_unsuspended', $staff->id, $user, ($on ? 'Suspended ' : 'Suspension lifted for ').Str::limit($user->email, 120, ''));
    }

    public function addNote(User $user, User $staff, string $body): AdminNote
    {
        $note = AdminNote::query()->create(['user_id' => $user->id, 'author_id' => $staff->id, 'body' => Str::limit(trim($body), 5000, '')]);
        $this->audit->record('admin_note_added', $staff->id, $user, 'Note added for '.Str::limit($user->email, 120, ''));

        return $note;
    }

    public function resendVerification(User $user, User $staff): bool
    {
        if ($user->email_verified_at !== null) {
            return false;
        }
        $user->sendEmailVerificationNotification();
        $this->audit->record('verification_resent', $staff->id, $user, 'Confirmation email sent again to '.Str::limit($user->email, 120, ''));

        return true;
    }

    /** Turns two-step sign-in off, so the person can sign in with the password alone and set it up again. */
    public function resetTwoFactor(User $user, User $staff): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        $this->audit->record('mfa_reset_by_staff', $staff->id, $user, 'Two-step sign-in reset for '.Str::limit($user->email, 120, ''));

        return true;
    }

    public function signOutEverywhere(User $user, User $staff): int
    {
        $count = app(AccountSessions::class)->signOutEverywhere($user);
        $this->audit->record('signed_out_by_staff', $staff->id, $user, 'Signed out everywhere by staff: '.Str::limit($user->email, 120, ''));

        return $count;
    }

    /** Starts the 7-day deletion, as if the person asked for it (signed out everywhere, the email with the date). */
    public function delete(User $user, User $staff): Carbon
    {
        $when = app(AccountDeletion::class)->schedule($user);
        $this->audit->record('account_deletion_by_staff', $staff->id, $user, 'Deletion started by staff for '.Str::limit($user->email, 120, ''));

        return $when;
    }

    /** Whether the person lets support see their recordings now (Profile → Support access). */
    public static function supportAccess(User $user): bool
    {
        return $user->support_access_until !== null && $user->support_access_until->isFuture();
    }
}
