<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Transcript;
use App\Services\AuditLog;
use App\Services\TranscriptExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** GET /api/transcripts/{id}/export (since v1.1): the transcript as a file. */
final class ExportController extends Controller
{
    public function show(Request $request, string $id, TranscriptExporter $exporter): Response
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(TranscriptExporter::FORMATS)],
            'timestamps' => ['sometimes', 'boolean'],
            'speakers' => ['sometimes', 'boolean'],
        ]);
        /** @var Device $device */
        $device = $request->user();
        $transcript = Transcript::query()
            ->whereHas('captureSession', fn ($q) => $q->where('workspace_id', $device->workspace_id)->whereNull('deleted_at'))
            ->find($id);
        if (! $transcript instanceof Transcript) {
            throw new ApiError(404, 'transcript_not_found', 'Transcript not found.');
        }
        if ($transcript->status !== TranscriptStatus::Completed) {
            throw new ApiError(409, 'transcript_not_ready', 'The transcript is not finished yet.');
        }
        $file = $exporter->export($transcript, $data['format'], [
            'timestamps' => $request->boolean('timestamps', true),
            'speakers' => $request->boolean('speakers', true),
        ]);
        app(AuditLog::class)->record('transcript_exported', $device->user_id, $transcript->captureSession, "Transcript exported as {$data['format']}", ['format' => $data['format']]);

        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => self::disposition($file['filename']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** `attachment` with the UTF-8 name and an ASCII fallback for old clients. */
    public static function disposition(string $filename): string
    {
        $fallback = Str::ascii($filename);
        $fallback = preg_replace('/[^A-Za-z0-9 ._-]+/', '', $fallback) ?: 'transcript';

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback);
    }
}
