<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecordingUploadResource;
use App\Models\Device;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\Data\CompleteOutcome;
use App\Services\RecordingCipher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class RecordingController extends Controller
{
    /** GET /api/recordings/{id} — resume state. */
    public function show(Request $request, string $id): RecordingUploadResource
    {
        return new RecordingUploadResource($this->findRecording($request, $id));
    }

    /** POST /api/recordings/{id}/chunks — raw octet-stream body + Upload-Chunk-Index / Upload-Chunk-Sha256 headers. */
    public function chunk(Request $request, string $id, ChunkedUploadService $uploads): JsonResponse
    {
        $recording = $this->findRecording($request, $id);
        $index = $request->header('Upload-Chunk-Index');
        if ($index === null || ! ctype_digit((string) $index)) {
            throw ValidationException::withMessages(['index' => 'Upload-Chunk-Index header is required.']);
        }
        $body = $request->getContent(true);
        if (! is_resource($body)) {
            throw ValidationException::withMessages(['chunk' => 'Empty chunk body.']);
        }

        $recording = $uploads->storeChunk($recording, (int) $index, (string) $request->header('Upload-Chunk-Sha256', ''), $body);

        return response()->json([
            'received_chunks' => $recording->received_chunks,
            'total_chunks' => $recording->total_chunks,
            'status' => $recording->upload_status->value,
        ]);
    }

    /**
     * POST /api/recordings/{id}/complete — 202 `assembling`/`ready`; 422 `upload_incomplete` (send the missing chunks)
     * or 422 `verification_failed` (the parts were discarded: send the whole file again).
     */
    public function complete(Request $request, string $id, ChunkedUploadService $uploads): JsonResponse
    {
        $recording = $this->findRecording($request, $id);
        $outcome = $uploads->markComplete($recording);

        return match ($outcome->state) {
            CompleteOutcome::INCOMPLETE => throw new ApiError(422, 'upload_incomplete', 'Upload is incomplete.', ['missing_chunks' => $outcome->missing]),
            CompleteOutcome::FAILED => throw new ApiError(422, 'verification_failed', ChunkedUploadService::VERIFICATION_FAILED),
            default => response()->json(['status' => $outcome->state], 202),
        };
    }

    /** GET /api/recordings/{id}/audio — temporary signed URL (route middleware `signed`), decrypted, Range-capable. */
    public function audio(string $recording): BinaryFileResponse
    {
        $model = Recording::query()->findOrFail($recording);
        // A recording in the undo bin (B17) is not played, even through a link signed before the delete.
        abort_unless($model->isReady() && ! $model->captureSession->trashed(), 404);

        return self::streamDecrypted($model);
    }

    /**
     * Decrypts the recording into a private temp file (constant memory, whatever the length) and serves it with Range
     * support; the file is deleted once sent, and TempFileSweeper removes any copy whose response never went out.
     */
    public static function streamDecrypted(Recording $recording): BinaryFileResponse
    {
        $plain = app(ChunkedUploadService::class)->tempPath('play');
        RecordingCipher::fromConfig()->decryptToFile(
            Storage::disk('recordings')->path((string) $recording->stored_path),
            $plain,
            $recording->id,
        );
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        $size = (int) filesize($plain);
        if (self::rangeStartsPastEnd(request(), $size)) {
            @unlink($plain);
            throw new HttpResponseException(response('', 416, $headers + ['Content-Range' => "bytes */{$size}"]));
        }

        $response = new BinaryFileResponse($plain, 200, ['Content-Type' => $recording->mime, ...$headers], false, null, false, false);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * True for `Range: bytes=<start>-[<end>]` whose start is at or past the end of the file. Symfony answers 416 only
     * when the end is given and beyond the start; an open range starting at the size falls through to a full 200
     * (vendor/symfony/http-foundation/BinaryFileResponse.php prepare(): only `$start <= $end` is handled).
     * Mirrors Symfony's other rules: GET only, and an If-Range (never valid here: no ETag or Last-Modified) means the
     * whole file.
     */
    private static function rangeStartsPastEnd(Request $request, int $size): bool
    {
        if (! $request->isMethod('GET') || $request->headers->has('If-Range')) {
            return false;
        }
        if (preg_match('/^bytes=(\d+)-(\d*)$/', (string) $request->headers->get('Range', ''), $m) !== 1) {
            return false;
        }
        // Compare as digits: a start too long for an integer is past the end of any file.
        $start = ltrim($m[1], '0');
        if (strlen($start) > 18) {
            return true;
        }
        $start = (int) $start;
        $end = ltrim($m[2], '0');
        if ($m[2] !== '' && strlen($end) <= 18 && (int) $end < $start) {
            return false; // end before start: an invalid range, which Symfony ignores (whole file)
        }

        return $start >= $size;
    }

    private function findRecording(Request $request, string $id): Recording
    {
        /** @var Device $device */
        $device = $request->user();

        return Recording::query()
            ->whereHas('captureSession', fn ($q) => $q->where('user_id', $device->user_id)->whereNull('deleted_at'))
            ->findOrFail($id);
    }
}
