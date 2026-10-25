<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TranscriptStatus;
use App\Http\Controllers\Api\ExportController;
use App\Models\Transcript;
use App\Models\User;
use App\Services\TranscriptExporter;
use Illuminate\Http\Response;

/** Console exports (session + super-admin middleware on the route); `html` opens inline for printing to PDF. */
final class AdminExportController extends Controller
{
    public function __invoke(string $transcript, string $format, TranscriptExporter $exporter): Response
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_super_admin, 403);
        abort_unless(in_array($format, TranscriptExporter::FORMATS, true), 404);
        $model = Transcript::query()->find($transcript);
        abort_if(! $model instanceof Transcript || (string) $model->captureSession->user_id !== (string) $user->getKey(), 404);
        abort_if($model->captureSession->trashed() || $model->status !== TranscriptStatus::Completed, 404);
        $file = $exporter->export($model, $format);

        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => $format === 'html' ? 'inline' : ExportController::disposition($file['filename']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
