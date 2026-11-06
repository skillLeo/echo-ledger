<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Mail\LoginAlertMail;
use App\Models\Device;
use App\Models\User;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * "New sign-in" emails (since v1.5): every new device on the account (signed in or paired), and the console opened
 * from a browser the account had not used before (KnownBrowsers).
 */
final class LoginAlerts
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function deviceSignedIn(Device $device): void
    {
        $user = User::query()->find($device->user_id);
        if ($user instanceof User) {
            $this->send($user, "{$device->name}, {$device->platform->getLabel()}", $device->last_ip, $device->last_country);
        }
    }

    public function browserSignedIn(User $user, string $browser, ?string $ip, ?string $country): void
    {
        $this->send($user, $browser, $ip, $country);
    }

    private function send(User $user, string $what, ?string $ip, ?string $country): void
    {
        $when = now()->setTimezone($this->settings->displayTimezone())->format('M j, Y \a\t g:i A T');
        $devices = (Filament::getPanel('admin')->getProfileUrl() ?? url('/admin/profile')).'#devices';
        Mail::to($user->email)->queue(new LoginAlertMail($what, $when, $ip, $country, $devices));
    }
}
