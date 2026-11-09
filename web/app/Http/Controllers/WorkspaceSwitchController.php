<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's workspace switcher (since v1.5): makes another workspace of the person their current one. A
 * workspace they do not belong to is the same 404 as an unknown id.
 */
final class WorkspaceSwitchController extends Controller
{
    public function __invoke(Request $request, int $workspace, Workspaces $workspaces): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');
        $target = $user->workspaces()->whereKey($workspace)->first();
        abort_unless($target instanceof Workspace, 404);
        $workspaces->switchTo($user, $target);

        return redirect()->to(Filament::getPanel('app')->getUrl() ?? '/app');
    }
}
