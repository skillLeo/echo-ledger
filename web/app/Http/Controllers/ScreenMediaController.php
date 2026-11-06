<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CaptureSession;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Services\Screens\ScreenStore;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\Response;

/**
 * A screen moment's picture or thumbnail, decrypted for this answer only (v1.5): from the signed link the API hands
 * out (30 minutes, no token), and on the console for the workspace's own recordings. A recording in the undo bin shows
 * none, the same 404 as an unknown screen.
 */
final class ScreenMediaController extends Controller
{
    public function __construct(private readonly ScreenStore $store) {}

    /** The `signed` middleware has checked the link; the recording must still be there. */
    public function signed(string $screen, string $variant): Response
    {
        $model = Screen::query()->withoutGlobalScopes()->find($screen);
        $session = $model instanceof Screen
            ? CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)->find($model->capture_session_id) : null;
        abort_if(! $session instanceof CaptureSession, 404);

        return $this->send($model, $variant === 'thumb');
    }

    /** The console (session + workspace-member middleware on the route): screens of the current workspace only. */
    public function console(string $screen, string $variant): Response
    {
        $model = Screen::query()->withoutGlobalScopes()->find($screen);
        $session = $model instanceof Screen
            ? CaptureSession::query()->where('workspace_id', CurrentWorkspace::id())->find($model->capture_session_id) : null;
        abort_if(! $session instanceof CaptureSession, 404);

        return $this->send($model, $variant === 'thumb');
    }

    private function send(Screen $screen, bool $thumb): Response
    {
        $bytes = $this->store->picture($screen, $thumb);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $thumb ? 'image/webp' : $screen->mime,
            'Content-Length' => (string) strlen($bytes),
            // The person's own picture: their browser may keep it while the link is valid, nothing in between may.
            'Cache-Control' => 'private, max-age=1800',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
