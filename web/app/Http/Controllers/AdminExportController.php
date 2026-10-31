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
        // "PDF (print)" on the recording page: the print page with a Save as PDF button, and the print dialog opens.
        $body = $format === 'html' && request()->boolean('print') ? self::withPrintBar($file['body']) : $file['body'];

        return response($body, 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => $format === 'html' ? 'inline' : ExportController::disposition($file['filename']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A bar above the print page (hidden in print by the page's `.noprint`) and the print dialog once fonts are in. */
    private static function withPrintBar(string $html): string
    {
        $bar = '<div class="noprint" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 24px;padding:10px 14px;border:1px solid #ddd;border-radius:10px;font-size:14px;color:#444">'
            .'<span>To keep a PDF, choose “Save as PDF” as the printer.</span>'
            .'<button type="button" onclick="window.print()" style="font:inherit;padding:6px 14px;border:0;border-radius:8px;background:#7c3aed;color:#fff;cursor:pointer">Print or save as PDF</button>'
            .'</div><script>window.addEventListener("load",function(){(document.fonts?document.fonts.ready:Promise.resolve()).then(function(){window.print();});});</script>';
        $at = strpos($html, '<body>');

        return $at === false ? $html : substr_replace($html, '<body>'.$bar, $at, strlen('<body>'));
    }
}
