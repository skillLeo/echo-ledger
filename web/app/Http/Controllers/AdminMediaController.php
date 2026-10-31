<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\RecordingController;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Services\TranscriptExporter;
use App\Services\Workspaces\CurrentWorkspace;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Console playback for the Filament transcript player (session + workspace-member middleware on the route), and
 * with `?download=1` the audio file to save, named after the recording's title and date.
 */
final class AdminMediaController extends Controller
{
    /** File extension of each stored audio type, for the saved file's name. */
    private const EXTENSIONS = [
        'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/opus' => 'opus',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
        'audio/webm' => 'webm',
        'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
        'audio/amr' => 'amr',
    ];

    public function __invoke(Recording $recording): BinaryFileResponse
    {
        // Same answer as an unknown id: a workspace must not learn that another workspace's recording exists.
        $session = CaptureSession::query()->where('workspace_id', CurrentWorkspace::id())->find($recording->capture_session_id);
        abort_if(! $session instanceof CaptureSession || ! $recording->isReady(), 404);

        $response = RecordingController::streamDecrypted($recording);
        if (request()->boolean('download')) {
            $response->headers->set('Content-Disposition', ExportController::disposition(
                TranscriptExporter::filename($session, self::extensionFor($recording->mime))));
        }

        return $response;
    }

    public static function extensionFor(string $mime): string
    {
        return self::EXTENSIONS[strtolower($mime)] ?? 'audio';
    }
}
