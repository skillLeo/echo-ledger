<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\Shell;
use App\Models\Client;
use App\Models\User;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Notifications\Notification;

/**
 * Recordings dragged onto a place (since v1.6, P4.2): onto a project they move there, onto the Inbox they leave their
 * project, onto Pinned they are pinned. Only the person's own recordings and projects in the current workspace; any
 * other id is 404, and the small (read-only) console may not move anything (403).
 */
trait DropsRecordings
{
    /** @param  array<int, mixed>  $ids */
    public function dropRecordings(string $target, array $ids): void
    {
        abort_unless(ConsoleAccess::full() && ! ConsoleAccess::readOnly(), 403);
        $ids = array_values(array_unique(array_filter($ids, fn (mixed $id): bool => is_string($id) && $id !== '' && strlen($id) <= 64)));
        abort_if($ids === [], 404);
        [$action, $clientId] = match (true) {
            $target === 'inbox' => ['move', null],
            $target === 'pinned' => ['pin', null],
            str_starts_with($target, 'project:') && strlen($target) > 8 => ['move', substr($target, 8)],
            default => [null, null],
        };
        abort_if($action === null, 404);
        $user = auth()->user();
        // Throws 404 client_not_found for another person's or another workspace's project.
        $changed = app(LibraryOrganiser::class)->bulk((int) CurrentWorkspace::id(), $ids, $action, $clientId, null, $user instanceof User ? $user : null);
        abort_if($changed === 0, 404);
        Shell::forget();
        $what = $changed === 1 ? '1 recording' : $changed.' recordings';
        $where = match ($target) {
            'inbox' => 'Moved '.$what.' to the Inbox',
            'pinned' => 'Pinned '.$what,
            default => 'Moved '.$what.' to '.(Client::query()->whereKey($clientId)->value('name') ?? 'the project'),
        };
        Notification::make()->title($where)->success()->send();
        $this->dispatch('el-library-changed');
        $this->dispatch('refresh-sidebar');
    }
}
