<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecordingUploadResource;
use App\Models\Device;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\RecordingCipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            return response()->json(['message' => 'Upload-Chunk-Index header is required.'], 422);
        }
        $body = $request->getContent(true);
        if (! is_resource($body)) {
            return response()->json(['message' => 'Empty chunk body.'], 422);
        }

        $recording = $uploads->storeChunk($recording, (int) $index, (string) $request->header('Upload-Chunk-Sha256', ''), $body);

        return response()->json([
            'received_chunks' => array_values($recording->received_chunks),
            'total_chunks' => $recording->total_chunks,
            'status' => $recording->upload_status->value,
        ]);
    }

    /** POST /api/recordings/{id}/complete */
    public function complete(Request $request, string $id, ChunkedUploadService $uploads): JsonResponse
    {
        $recording = $this->findRecording($request, $id);
        $missing = $uploads->markComplete($recording);
        if ($missing !== []) {
            return response()->json(['message' => 'Upload is incomplete.', 'missing_chunks' => $missing], 422);
        }

        return response()->json(['status' => $recording->refresh()->upload_status->value], 202);
    }

    /** GET /api/recordings/{id}/audio — temporary signed URL (route middleware `signed`), decrypted, Range-capable. */
    public function audio(string $recording): BinaryFileResponse
    {
        $model = Recording::query()->findOrFail($recording);
        abort_unless($model->isReady(), 404);

        return self::streamDecrypted($model);
    }

    public static function streamDecrypted(Recording $recording): BinaryFileResponse
    {
        $plain = RecordingCipher::fromConfig()->decryptToMemory(
            Storage::disk('recordings')->path((string) $recording->stored_path),
            $recording->id,
        );

        $response = new BinaryFileResponse($plain, 200, [
            'Content-Type' => $recording->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], false, null, false, false);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
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
