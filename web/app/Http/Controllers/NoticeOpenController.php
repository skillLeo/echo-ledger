<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filament\Support\NoticeTargets;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * A notification of the bell opened (since v1.6.1, docs/DESIGN_SYSTEM.md "Notices"): it is marked read and the browser
 * goes where it is about (NoticeTargets), or to Activity when it is about nothing in particular. Only the person's own
 * notifications: anyone else's id is the same 404 as an unknown one, and nothing is marked.
 */
final class NoticeOpenController extends Controller
{
    public function __invoke(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');
        abort_unless(Str::isUuid($notification), 404);
        $found = $user->notifications()->whereKey($notification)->first();
        abort_unless($found instanceof DatabaseNotification, 404);
        $found->markAsRead();

        return redirect()->to(NoticeTargets::forNotification($found, $user) ?? NoticeTargets::fallback());
    }
}
