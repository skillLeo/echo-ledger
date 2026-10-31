<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Models\Recording;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\ConsoleUploads;
use App\Services\Data\CompleteOutcome;
use App\Services\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Library → Upload in the console (session + workspace-member middleware on the routes): the browser's chunked,
 * resumable uploads of the signed-in person's files into the current workspace. Answers are JSON for the uploader on
 * the sessions list: `{"message", "code", ...}` on an error, like the device API.
 */
final class ConsoleUploadController extends Controller
{
    public function __construct(private readonly ConsoleUploads $imports) {}

    /**
     * POST /admin-upload/recordings: 201 a new upload, 200 the same file's unfinished upload (send its
     * `missing_chunks`), 409 `duplicate_recording` when the file is already in the Library.
     */
    public function store(Request $request): JsonResponse
    {
        $max = (int) config('echoledger.max_recording_bytes');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:1000'],
            'size' => ['required', 'integer', 'min:1', 'max:'.$max],
            'sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'type' => ['nullable', 'string', 'max:255'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'client_id' => ['nullable', 'string', 'max:26'],
            'language' => ['nullable', 'string', 'max:16'],
        ], [
            'size.min' => 'This file is empty.',
            'size.max' => 'This file is larger than the '.self::gigabytes($max).' limit.',
        ]);

        return $this->answer(function () use ($request, $data): JsonResponse {
            /** @var array{name: string, size: int, sha256: string, type?: string|null, duration_ms?: int|null, client_id?: string|null, language?: string|null} $data */
            ['recording' => $recording, 'resumed' => $resumed] = $this->imports->start(
                $this->user($request), $this->workspace(), $data, ChunkedUploadService::advertisedChunkSize());

            return response()->json(['resumed' => $resumed, ...$this->imports->state($recording)], $resumed ? 200 : 201);
        });
    }

    /** GET /admin-upload/recordings/{id}: where the upload is, to resume it after a dropped connection. */
    public function show(Request $request, string $recording): JsonResponse
    {
        return response()->json($this->imports->state($this->recording($request, $recording)));
    }

    /** POST /admin-upload/recordings/{id}/chunks/{index}: raw bytes of one chunk, `Upload-Chunk-Sha256` header. */
    public function chunk(Request $request, string $recording, string $index): JsonResponse
    {
        $model = $this->recording($request, $recording);
        $body = $request->getContent(true);
        if (! is_resource($body)) {
            throw ValidationException::withMessages(['chunk' => 'Empty chunk body.']);
        }

        return $this->answer(function () use ($model, $index, $request, $body): JsonResponse {
            $stored = $this->imports->storeChunk($model, (int) $index, (string) $request->header('Upload-Chunk-Sha256', ''), $body);

            return response()->json([
                'received_chunks' => $stored->received_chunks,
                'total_chunks' => $stored->total_chunks,
                'status' => $stored->upload_status->value,
            ]);
        });
    }

    /**
     * POST /admin-upload/recordings/{id}/complete: 202 `assembling` or `ready` (the transcript starts on its own);
     * 422 `upload_incomplete` with `missing_chunks`, or `verification_failed` (send the whole file again).
     */
    public function complete(Request $request, string $recording): JsonResponse
    {
        $model = $this->recording($request, $recording);

        return $this->answer(function () use ($model): JsonResponse {
            $outcome = $this->imports->complete($model);

            return match ($outcome->state) {
                CompleteOutcome::INCOMPLETE => throw new ApiError(422, 'upload_incomplete', 'Upload is incomplete.', ['missing_chunks' => $outcome->missing]),
                CompleteOutcome::FAILED => throw new ApiError(422, 'verification_failed', ChunkedUploadService::VERIFICATION_FAILED),
                default => response()->json(['status' => $outcome->state, ...$this->imports->sessionLink($model->captureSession)], 202),
            };
        });
    }

    /** DELETE /admin-upload/recordings/{id}: cancel an unfinished upload; what was sent of it is deleted. */
    public function destroy(Request $request, string $recording): JsonResponse
    {
        $model = $this->recording($request, $recording);

        return $this->answer(function () use ($model): JsonResponse {
            $this->imports->cancel($model);

            return response()->json(['status' => 'cancelled']);
        });
    }

    /** @param  Closure(): JsonResponse  $action */
    private function answer(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ApiError $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->apiCode(), ...$e->extra()], $e->getStatusCode());
        } catch (HttpExceptionInterface $e) {
            // Fixed texts from the chunk checks (too large, already complete); never a trace, also with APP_DEBUG.
            return response()->json(['message' => $e->getMessage() !== '' ? $e->getMessage() : 'The upload was refused.', 'code' => 'upload_refused'], $e->getStatusCode());
        }
    }

    /** Someone else's, another workspace's or a cancelled upload is the same 404 as an unknown id. */
    private function recording(Request $request, string $id): Recording
    {
        $recording = $this->imports->find($this->user($request), $this->workspace(), $id);
        abort_if($recording === null, 404);

        return $recording;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** A super admin without a workspace has nowhere to put a recording. */
    private function workspace(): int
    {
        $id = CurrentWorkspace::id();
        abort_if($id === null || $id === CurrentWorkspace::NONE, 403);

        return $id;
    }

    private static function gigabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 ** 3, 1), '0'), '.').' GB';
    }
}
