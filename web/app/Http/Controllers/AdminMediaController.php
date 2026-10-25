<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\RecordingController;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Services\Workspaces\CurrentWorkspace;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Console playback for the Filament transcript player (session + workspace-member middleware on the route). */
final class AdminMediaController extends Controller
{
    public function __invoke(Recording $recording): BinaryFileResponse
    {
        // Same answer as an unknown id: a workspace must not learn that another workspace's recording exists.
        $session = CaptureSession::query()->where('workspace_id', CurrentWorkspace::id())->find($recording->capture_session_id);
        abort_if(! $session instanceof CaptureSession || ! $recording->isReady(), 404);

        return RecordingController::streamDecrypted($recording);
    }
}
