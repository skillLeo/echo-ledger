<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Workspaces\ConsoleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console routes outside Filament's pages (since v1.5): `full` ones (uploading) are for owners, admins and the super
 * admin; `open` ones (playing, exporting, the meeting pack) for the small read-only console too, unless Settings closes
 * the console to members and viewers.
 */
final class EnsureConsoleAccess
{
    public function handle(Request $request, Closure $next, string $level = 'open'): Response
    {
        abort_unless($level === 'full' ? ConsoleAccess::full() : ConsoleAccess::open(), 403);

        return $next($request);
    }
}
