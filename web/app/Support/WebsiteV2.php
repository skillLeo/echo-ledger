<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Services\InterfaceFlags;
use App\Services\Staff\StaffAccess;
use Illuminate\Http\Request;

/**
 * The v2.0 website (ui_v2.website): Home, Pricing and Download in the new design. Visitors are not signed in, so it is
 * on for them only once the flag is on for everyone. Before that, staff preview it: signed in, with the flag on for
 * them, or with ?preview=v2. Off, the site is exactly the classic one (tests/Feature/V2/SiteClassicGoldenTest.php).
 */
final class WebsiteV2
{
    public const PREVIEW = 'v2';

    public static function on(Request $request): bool
    {
        $flags = app(InterfaceFlags::class);
        if ($flags->forEveryone('ui_v2.website')) {
            return true;
        }
        $user = $request->user();
        if (! $user instanceof User || ! StaffAccess::isStaff($user)) {
            return false;
        }

        return $request->query('preview') === self::PREVIEW || $flags->active('ui_v2.website', $user);
    }

    /**
     * The query a staff preview keeps on the site's own links, so the pages stay in the preview.
     *
     * @return array<string, string>
     */
    public static function keep(Request $request): array
    {
        return $request->query('preview') === self::PREVIEW ? ['preview' => self::PREVIEW] : [];
    }
}
