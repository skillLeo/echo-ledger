<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\RecordingController;
use App\Models\Recording;
use App\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Admin playback for the Filament transcript player (session + MFA middleware on the route). */
final class AdminMediaController extends Controller
{
    public function __invoke(Recording $recording): BinaryFileResponse
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_super_admin, 403);
        abort_if($recording->captureSession->trashed() || ! $recording->isReady(), 404);

        return RecordingController::streamDecrypted($recording);
    }
}
