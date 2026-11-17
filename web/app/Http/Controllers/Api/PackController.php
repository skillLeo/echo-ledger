<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\AuditLog;
use App\Services\Screens\MeetingPack;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** GET /api/sessions/{id}/pack (since v1.5): the meeting pack, the transcript with its screen moments, as a file. */
final class PackController extends Controller
{
    public function show(Request $request, string $id, MeetingPack $pack): BinaryFileResponse
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(MeetingPack::FORMATS)],
            'view' => ['sometimes', 'nullable', Rule::in(MeetingPack::VIEWS)],
            'include_audio' => ['sometimes', 'boolean'],
            'part' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        /** @var Device $device */
        $device = $request->user();
        // An export: the workspace's own recordings only (a recording a teammate sent is read in place, never exported).
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->findOrFail($id);
        $file = $pack->build($session, $data['format'], $data['view'] ?? null, $request->boolean('include_audio'), part: (int) ($data['part'] ?? 1));
        app(AuditLog::class)->record('transcript_exported', $device->user_id, $session, "Meeting pack exported as {$data['format']}", ['format' => 'pack_'.$data['format']]);

        return self::send($file, inline: false);
    }

    /**
     * The written pack, deleted once it is sent.
     *
     * @param  array{path: string, name: string, mime: string, part?: int, parts?: int}  $file
     */
    public static function send(array $file, bool $inline): BinaryFileResponse
    {
        $response = response()->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
        if (! $inline) {
            $response->headers->set('Content-Disposition', ExportController::disposition($file['name']));
        }
        // Since v1.8 P4.4: a printed pack of a long meeting comes in parts (`part=`).
        $response->headers->set('X-Pack-Part', (string) ($file['part'] ?? 1));
        $response->headers->set('X-Pack-Parts', (string) ($file['parts'] ?? 1));

        return $response->deleteFileAfterSend(true);
    }
}
