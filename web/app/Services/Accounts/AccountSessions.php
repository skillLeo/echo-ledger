<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\DeviceSignInCode;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Connector\ConnectedApps;
use App\Services\WebSessions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where an account is signed in (since v1.5, Profile → Devices): its devices in every workspace and its console
 * browsers. "Sign out everywhere" revokes every device with its tokens, ends every console session, drops the
 * pairing and browser sign-in codes not used yet and changes the "remember me" token, so nothing stays or gets signed in.
 */
final class AccountSessions
{
    public function __construct(private readonly AuditLog $audit) {}

    /** @return Collection<int, Device> the account's devices in every workspace, most recently seen first */
    public function devices(User $user): Collection
    {
        return Device::query()->withoutGlobalScopes()->where('user_id', $user->id)
            ->orderByDesc('last_seen_at')->orderByDesc('created_at')->get();
    }

    public function revokeDevice(User $user, string $id): bool
    {
        $device = Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereKey($id)->first();
        if (! $device instanceof Device) {
            return false;
        }

        return (bool) $device->delete();
    }

    /** Returns how many devices were signed out. */
    public function signOutEverywhere(User $user): int
    {
        $devices = $this->devices($user);
        foreach ($devices as $device) {
            $device->delete();
        }
        // A code made a minute ago would otherwise still bring a device in afterwards.
        DevicePairingCode::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereNull('used_at')->delete();
        DeviceSignInCode::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereNull('used_at')->delete();
        if (WebSessions::available()) {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        // Since v1.5 the apps connected through the connector lose their access too.
        app(ConnectedApps::class)->disconnectAll($user);
        $this->audit->record('signed_out_everywhere', $user->id, $user, $devices->count() === 1 ? 'Signed out everywhere (1 device)' : "Signed out everywhere ({$devices->count()} devices)");

        return $devices->count();
    }
}
