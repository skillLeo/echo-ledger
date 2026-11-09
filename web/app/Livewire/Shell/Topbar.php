<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use Filament\Livewire\Topbar as FilamentTopbar;
use Illuminate\Contracts\View\View;

/**
 * The console's top bar (since v1.6, P4.3), beside the sidebar as in the desktop app: the workspace, search and
 * commands (Ctrl/⌘+K), "+" to make something, the bell, help and the avatar menu. The admin panel has the same bar
 * without the workspace and "+".
 */
class Topbar extends FilamentTopbar
{
    public function render(): View
    {
        return view('filament.shell.topbar');
    }
}
