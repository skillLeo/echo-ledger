<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Models\StaffRole;
use App\Models\User;

/**
 * Who works on the platform (since v1.6, P2): the admin panel at /admin is for staff only, and every page and action
 * there asks for one permission. The super admin (the server's operator) has every permission; other staff have a
 * staff role (Admin, Support, Billing, Read-only, or one made on the Staff page) with its permissions. A suspended
 * account is nobody's staff. Customers are never staff; their own workspace roles live in App\Enums\WorkspaceRole and
 * never open /admin. Staff also need two-step sign-in before /admin opens (App\Http\Middleware\EnsureStaffTwoFactor).
 */
final class StaffAccess
{
    /** Every permission of the admin panel, with how the permissions matrix names it. */
    public const PERMISSIONS = [
        'customers.view' => 'See customers, their plans and usage',
        'customers.manage' => 'Change a customer\'s plan, limits and notes; suspend',
        'customers.support' => 'Support actions: resend emails, reset two-step sign-in, sign out devices',
        'customers.delete' => 'Delete a customer',
        'plans.view' => 'See plans and features',
        'plans.manage' => 'Make and change plans',
        'upgrades.manage' => 'Answer upgrade requests',
        'usage.view' => 'See usage and costs',
        'credentials.manage' => 'Provider keys (API credentials)',
        'settings.manage' => 'Server settings, sign-up, app versions, announcements, maintenance',
        'staff.manage' => 'Staff and their roles',
        'audit.view' => 'See the activity log',
        'devices.reports' => 'Read device reports and the Accuracy Lab',
    ];

    public static function isStaff(?User $user = null): bool
    {
        $user ??= self::current();

        return $user instanceof User && ! $user->isSuspended() && ($user->is_super_admin || self::role($user) instanceof StaffRole);
    }

    public static function can(string $permission, ?User $user = null): bool
    {
        $user ??= self::current();
        if (! isset(self::PERMISSIONS[$permission]) || ! $user instanceof User || ! self::isStaff($user)) {
            return false;
        }

        return $user->is_super_admin || self::role($user)?->grants($permission) === true;
    }

    /** "Super admin", the role's name, or null for someone who is not staff. */
    public static function roleName(User $user): ?string
    {
        return $user->is_super_admin ? 'Super admin' : self::role($user)?->name;
    }

    private static function role(User $user): ?StaffRole
    {
        if ($user->staff_role_id === null) {
            return null;
        }
        $role = $user->staffRole;

        return $role instanceof StaffRole && $role->id === $user->staff_role_id ? $role : null;
    }

    private static function current(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
