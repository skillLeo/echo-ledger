<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Mail\UnusualUsageMail;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The unusual-usage alert (since v1.6, P1.5): when one person's recordings of the last 24 hours add up to more than the
 * hours the super admin set (Settings → Sign-up, default 6), every super admin gets an email and a notification, at
 * most once a day per person. Only lengths are counted, never anything that was said.
 */
final class UnusualUsage
{
    public const DEFAULT_HOURS = 6;

    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLog $audit) {}

    public function hoursPerDay(): int
    {
        $value = $this->settings->get('abuse.hours_per_day', self::DEFAULT_HOURS);

        return is_numeric($value) ? max(1, min(24 * 7, (int) $value)) : self::DEFAULT_HOURS;
    }

    /** Hours of audio the person's recordings of the last 24 hours hold. */
    public function hoursToday(int $userId): float
    {
        $ms = (int) CaptureSession::acrossPeople()->withTrashed()->where('user_id', $userId)->where('created_at', '>', now()->subDay())->sum('duration_ms');

        return $ms / 3_600_000;
    }

    /** Checks the person after a recording arrived; never throws. */
    public function check(int $userId): void
    {
        try {
            $hours = $this->hoursToday($userId);
            $limit = $this->hoursPerDay();
            if ($hours <= $limit || AuditEvent::query()->where('event', 'unusual_usage')->where('user_id', $userId)->where('created_at', '>', now()->subDay())->exists()) {
                return;
            }
            $user = User::query()->find($userId);
            if (! $user instanceof User) {
                return;
            }
            $rounded = round($hours, 1);
            $this->audit->record('unusual_usage', $user->id, $user, "{$rounded} hours of recordings in a day (alert above {$limit})");
            // Since v1.6 the staff who can suspend an account hear about it too.
            $staff = User::query()->where(fn ($q) => $q->where('is_super_admin', true)->orWhereNotNull('staff_role_id'))->get()
                ->filter(fn (User $u): bool => StaffAccess::can('customers.manage', $u));
            foreach ($staff as $admin) {
                Mail::to($admin->email)->queue(new UnusualUsageMail($user->name, $user->email, $rounded, $limit, url('/admin/customers/'.$user->id)));
                app(ActivityFeed::class)->post($admin, 'unusual_usage', 'Unusual use', "{$user->name} ({$user->email}) recorded {$rounded} hours in a day.", null, 'heroicon-o-exclamation-triangle');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
