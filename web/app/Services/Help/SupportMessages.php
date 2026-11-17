<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Mail\SupportMessageMail;
use App\Models\Device;
use App\Models\DeviceReport;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Mail;

/**
 * Contact support (v1.8 P6.4): an email to the server's support address (Admin → Settings → Email, default
 * contact@<the app's domain>) with the person's name and email to reply to. A device report goes with it only when
 * they tick it: the device's own from the apps, their devices' latest from the console. The audit log keeps that a
 * message was sent, never its words.
 */
final class SupportMessages
{
    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLog $audit) {}

    /** The address support messages go to. */
    public function address(): string
    {
        $set = $this->settings->get('support.email');
        if (is_string($set) && filter_var($set, FILTER_VALIDATE_EMAIL) !== false) {
            return $set;
        }
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        return 'contact@'.preg_replace('/^www\./', '', $host);
    }

    /** Sends [$message] from [$user]; with [$includeReport], [$device]'s report (or, from the console, their devices'). */
    public function send(User $user, string $subject, string $message, bool $includeReport, ?Device $device = null): void
    {
        $reports = [];
        if ($includeReport) {
            $reports = DeviceReport::query()->withoutGlobalScopes()->where('user_id', $user->id)
                ->when($device !== null, fn ($q) => $q->where('device_id', $device?->id))
                ->latest('reported_at')->limit($device !== null ? 1 : 3)->get()
                ->map(fn (DeviceReport $r): array => [
                    'device_id' => $r->device_id, 'platform' => $r->platform, 'brand' => $r->brand, 'model' => $r->model,
                    'os_version' => $r->os_version, 'app_version' => $r->app_version, 'checks' => $r->checks, 'reported_at' => $r->reported_at->toAtomString(),
                ])->values()->all();
        }
        Mail::to($this->address())->queue(new SupportMessageMail($user->name, $user->email, trim($subject), trim($message), $reports, $device?->platform->value));
        $this->audit->record('support_message_sent', $user->id, $user, 'Wrote to support'.($reports !== [] ? ', with a device report' : ''), ['report' => $reports !== []]);
    }
}
