<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\SentItem;
use App\Models\User;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Notifications\Notification;

/** "Stop sharing" in the Sent to list (SharingActions::sentTo) of the recording's and the project's page. */
trait StopsSharing
{
    public function stopSharing(string $id): void
    {
        $user = auth()->user();
        $teammates = app(Teammates::class);
        $item = $user instanceof User ? $teammates->sentItem($user, (int) CurrentWorkspace::id(), $id) : null;
        if (! $item instanceof SentItem) {
            Notification::make()->title('That was already taken back')->success()->send();

            return;
        }
        $teammates->stopSharing($item);
        $this->unmountAction();
        // Toasts render their title as HTML: a name is text.
        Notification::make()->title('Stopped sharing with '.e((string) $item->recipient?->name))->body('A copy they already saved stays theirs.')->success()->send();
    }
}
