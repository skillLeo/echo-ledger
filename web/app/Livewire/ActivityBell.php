<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Support\Notice;
use App\Models\User;
use App\Services\ActionBadge;
use App\Services\ActivityFeed;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The app's bell (since v1.6, P6): the same list of notifications, with a number that counts only what needs the person
 * (App\Services\ActionBadge), "9+" above nine. Since v1.6.1 each card opens where it is about and marks itself read
 * (the notices.open route), and × or a swipe takes it off the list.
 */
class ActivityBell extends DatabaseNotifications
{
    public function getNotification(DatabaseNotification $notification): Notification
    {
        $notice = parent::getNotification($notification);
        if (! $notice instanceof Notice) {
            return $notice;
        }

        // Since W-218 in the reader's language when the notification keeps its lines (older ones as they were stored);
        // escaped, as the bell renders HTML and the words hold names and titles people typed.
        if (is_array($notification->data['viewData']['lines'] ?? null)) {
            $words = ActivityFeed::localized($notification);
            $notice->title(e($words['title']))->body(e($words['body']));
        }

        // The whole card opens it, so its own Open button goes.
        return Notice::withoutOpenAction($notice)->url(route('notices.open', ['notification' => $notification->getKey()], absolute: false));
    }

    public function getTrigger(): ?View
    {
        $user = $this->getUser();
        $count = $user instanceof User ? app(ActionBadge::class)->count($user, (int) CurrentWorkspace::id()) : 0;

        return view('filament.activity-bell-trigger', ['badge' => ActionBadge::label($count), 'count' => $count]);
    }
}
