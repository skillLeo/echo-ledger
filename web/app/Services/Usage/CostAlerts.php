<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Mail\CostAlertMail;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The monthly budget alert (since v1.6, P2.6, Admin panel → Server usage → Budget alert): when the whole server's
 * estimated cost this month passes 80% of the budget, and again at 100%, the staff who see usage get a notice (kind
 * `cost_alert`) and an email, once per level and month. An alert only: nothing stops (the monthly limit in Settings
 * is what stops transcription).
 */
final class CostAlerts
{
    public const SETTING = 'usage.alert_usd';

    public const SENT = 'usage.alert_sent';

    public const LEVELS = [80, 100];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function budget(): ?float
    {
        $value = $this->settings->get(self::SETTING);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** US dollars this month on the whole server, as the ledger priced each row. */
    public function spent(): float
    {
        $period = UsagePeriod::current($this->settings);

        return (float) UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)->where('billable', true)
            ->where('occurred_at', '>=', $period['start'])->where('occurred_at', '<', $period['end'])->sum('cost_usd');
    }

    /** @return array{budget: float|null, spent: float, percent: float|null} */
    public function status(): array
    {
        $budget = $this->budget();
        $spent = round($this->spent(), 2);

        return ['budget' => $budget, 'spent' => $spent, 'percent' => $budget === null ? null : round($spent / $budget * 100, 1)];
    }

    /** After a new ledger row: tells staff of each level passed this month that they were not told of yet. */
    public function check(): void
    {
        try {
            $budget = $this->budget();
            if ($budget === null) {
                return;
            }
            $spent = $this->spent();
            $month = UsagePeriod::current($this->settings)['month'];
            $sent = $this->sentLevels($month);
            $due = array_values(array_filter(self::LEVELS, fn (int $level): bool => $spent >= $budget * $level / 100 && ! in_array($level, $sent, true)));
            if ($due === []) {
                return;
            }
            $level = max($due);
            $this->settings->set(self::SENT, $month.':'.implode(',', array_unique([...$sent, ...$due])));
            $title = $level >= 100 ? 'Monthly budget reached' : "Monthly budget at {$level}%";
            $body = '$'.number_format($spent, 2).' of $'.number_format($budget, 2).' this month (estimated). Nothing stops; see Server usage.';
            User::query()->where(fn ($q) => $q->where('is_super_admin', true)->orWhereNotNull('staff_role_id'))->get()
                ->filter(fn (User $staff): bool => StaffAccess::can('usage.view', $staff))
                ->each(function (User $staff) use ($title, $body, $spent, $budget, $level): void {
                    app(ActivityFeed::class)->post($staff, 'cost_alert', $title, $body, null, 'heroicon-o-bell-alert');
                    Mail::to($staff->email)->queue(new CostAlertMail($level, round($spent, 2), $budget, url('/admin/server-usage')));
                });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<int> */
    private function sentLevels(string $month): array
    {
        $raw = $this->settings->get(self::SENT);
        if (! is_string($raw) || ! str_starts_with($raw, $month.':')) {
            return [];
        }

        return array_values(array_map('intval', array_filter(explode(',', substr($raw, strlen($month) + 1)), 'is_numeric')));
    }
}
