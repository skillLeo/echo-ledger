<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;
use App\Services\Workspaces\Workspaces;

/** Console checks for devices (B20 roles): rename and revoke. */
final class DevicePolicy
{
    public function __construct(private readonly Workspaces $workspaces) {}

    public function update(User $user, Device $device): bool
    {
        return $this->workspaces->canManageDevice($user, $device);
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->workspaces->canManageDevice($user, $device);
    }
}
