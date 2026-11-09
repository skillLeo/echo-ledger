<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Filament\Concerns\DropsRecordings;
use App\Filament\Support\Shell;
use App\Models\User;
use Filament\Livewire\Sidebar as FilamentSidebar;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Renderless;

/**
 * The console's sidebar (since v1.6, P4.1), in the desktop app's frame: the brand, "Find a page", then Home · Library
 * (its smart folders) · Projects (the pinned ones) · Activity · Shared (with me, my links, request links) · Upload, and
 * at the bottom Help & shortcuts · Account · Collapse sidebar. The admin panel shows its own navigation in the same
 * frame. Its width, the rail and the open groups are the person's (App\Filament\Support\Shell).
 */
class Sidebar extends FilamentSidebar
{
    use DropsRecordings;

    /** A choice made in the sidebar (its width, the rail, a group opened, the style); nothing is drawn again. */
    #[Renderless]
    public function rememberShell(string $key, mixed $value): bool
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return Shell::remember($user, $key, $value);
    }

    public function render(): View
    {
        return view('filament.shell.sidebar');
    }
}
