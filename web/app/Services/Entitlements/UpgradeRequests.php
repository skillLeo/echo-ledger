<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\User;

/** Upgrade requests (since v1.6, P1.4). */
final class UpgradeRequests
{
    /** The newest request of [$user] as the entitlements show it (P1.4 keeps them). */
    public function latestPayload(User $user): null
    {
        return null;
    }
}
