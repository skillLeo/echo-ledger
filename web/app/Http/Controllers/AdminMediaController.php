<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\RecordingController;
use App\Models\Recording;
use App\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Admin playback for the Filament transcript player (session + super-admin middleware on the route). */
final class AdminMediaController extends Controller
{
    public function __invoke(Recording $recording): BinaryFileResponse
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_super_admin, 403);
        $session = $recording->captureSession;
        // Same answer as an unknown id: one admin must not learn that another admin's recording exists.
        abort_if($session === null || (string) $session->user_id !== (string) $user->getKey(), 404);
        abort_if($session->trashed() || ! $recording->isReady(), 404);

        return RecordingController::streamDecrypted($recording);
    }
}
