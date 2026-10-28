<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TranscriptStatus;
use App\Http\Controllers\Api\ExportController;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\AuditLog;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\Response;

/** Console exports (session + workspace-member middleware on the route); `html` opens inline for printing to PDF. */
final class AdminExportController extends Controller
{
    public function __invoke(string $transcript, string $format, TranscriptExporter $exporter): Response
    {
        abort_unless(in_array($format, TranscriptExporter::FORMATS, true), 404);
        $model = Transcript::query()->find($transcript);
        $session = $model instanceof Transcript
            ? CaptureSession::query()->where('workspace_id', CurrentWorkspace::id())->find($model->capture_session_id)
            : null;
        abort_if(! $session instanceof CaptureSession || $model->status !== TranscriptStatus::Completed, 404);
        // Since v1.2: ?view=roman|english exports a Show as view (when it is ready) instead of the original words.
        $view = request()->query('view');
        $file = $exporter->export($model, $format, ['view' => is_string($view) && array_key_exists($view, TranscriptViews::VIEWS) ? $view : null]);
        app(AuditLog::class)->record('transcript_exported', (int) auth()->id(), $session, "Transcript exported as {$format}", ['format' => $format]);

        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => $format === 'html' ? 'inline' : ExportController::disposition($file['filename']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
