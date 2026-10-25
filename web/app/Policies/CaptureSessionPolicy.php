<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CaptureSession;
use App\Models\User;
use App\Services\Workspaces\Workspaces;

/** Console checks for recordings (B20 roles); the API asks Workspaces::canDeleteSession() with the device's user. */
final class CaptureSessionPolicy
{
    public function __construct(private readonly Workspaces $workspaces) {}

    public function delete(User $user, CaptureSession $session): bool
    {
        return $this->workspaces->canDeleteSession($user, $session);
    }

    public function restore(User $user, CaptureSession $session): bool
    {
        return $this->workspaces->canDeleteSession($user, $session);
    }

    public function forceDelete(User $user, CaptureSession $session): bool
    {
        return $this->workspaces->canDeleteSession($user, $session);
    }
}
