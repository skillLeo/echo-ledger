<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\ActionBadge;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\View\View;

/**
 * The app's bell (since v1.6, P6): the same list of notifications, with a number that counts only what needs the person
 * (App\Services\ActionBadge), "9+" above nine.
 */
class ActivityBell extends DatabaseNotifications
{
    public function getTrigger(): ?View
    {
        $user = $this->getUser();
        $count = $user instanceof User ? app(ActionBadge::class)->count($user, (int) CurrentWorkspace::id()) : 0;

        return view('filament.activity-bell-trigger', ['badge' => ActionBadge::label($count), 'count' => $count]);
    }
}
