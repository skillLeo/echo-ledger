<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\Assist\AssistMonitor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Live Assist right now (plan B4) on Usage → Live Assist, as its own component: it re-renders every 30 seconds on its
 * own, so the page around it (the period's numbers, AssistStats) is not worked out again each time. Super admin only,
 * checked on every render as the page checks it. States, times and numbers only.
 */
final class LiveAssistNow extends Component
{
    public function mount(): void
    {
        self::authorizeViewer();
    }

    /** "Check the relay now" on the page added a check: show it at once. */
    #[On('relay-checked')]
    public function refreshNow(): void {}

    public function render(): View
    {
        self::authorizeViewer();

        return view('filament.pages.live-assist-now', ['now' => app(AssistMonitor::class)->now()]);
    }

    private static function authorizeViewer(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_super_admin && ! $user->isSuspended(), 403);
    }
}
