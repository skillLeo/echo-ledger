<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Models\RequestLink;
use App\Models\RequestUpload;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\ConsoleUploads;
use App\Services\Sharing\RequestLinks;
use App\Services\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The public page of a request link (`/r/{token}`, since v1.5) and its chunked uploads: no account, throttled per
 * address, everything checked against the link (active, its size, its number of files, the types it takes). Answers
 * to the uploader are JSON `{"message", "code"}`, like the console's Upload.
 */
final class RequestLinkPageController extends Controller
{
    private const HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, private',
        'X-Frame-Options' => 'DENY',
    ];

    public function __construct(private readonly RequestLinks $links) {}

    /** GET /r/{token}: the upload page, or the same "no longer available" page as a share link. */
    public function show(string $token): Response
    {
        $link = $this->links->usable($token);
        if ($link === null) {
            return response()->view('share.gone', [], 404, self::HEADERS);
        }
        $project = $this->links->project($link);

        return response()->view('share.request', [
            'link' => $link,
            'takesDocuments' => $project !== null,
            'config' => [
                'store' => url('/r/'.$token.'/files'),
                'csrf' => csrf_token(),
                'maxBytes' => $link->max_mb * 1024 * 1024,
                'maxDocumentBytes' => ClientWorkspace::MAX_FILE_BYTES,
                'maxFiles' => $link->max_files,
                'audio' => array_keys(ConsoleUploads::EXTENSIONS),
                'documents' => $project !== null ? array_keys(RequestLinks::DOCUMENTS) : [],
                'nativeHashMaxBytes' => 100 * 1024 * 1024,
            ],
            'pageUrl' => url('/r/'.$token),
        ], 200, self::HEADERS);
    }

    /** POST /r/{token}/files: 201 a new upload, 200 the same file's unfinished one (send its `missing_chunks`). */
    public function store(Request $request, string $token): JsonResponse
    {
        return $this->forLink($token, function (RequestLink $link) use ($request): JsonResponse {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:1000'],
                'size' => ['required', 'integer', 'min:1'],
                'sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
                'type' => ['nullable', 'string', 'max:255'],
            ], ['size.min' => 'This file is empty.']);
            /** @var array{name: string, size: int, sha256: string, type?: string|null} $data */
            $upload = $this->links->start($link, $data);

            return response()->json($this->links->state($upload), $upload->wasRecentlyCreated ? 201 : 200);
        });
    }

    /** POST /r/{token}/files/{upload}/chunks/{index}: raw bytes of one chunk, `Upload-Chunk-Sha256` header. */
    public function chunk(Request $request, string $token, string $upload, string $index): JsonResponse
    {
        return $this->forLink($token, function (RequestLink $link) use ($request, $upload, $index): JsonResponse {
            $body = $request->getContent(true);
            if (! is_resource($body)) {
                throw new ApiError(422, 'validation_failed', 'Empty chunk body.');
            }
            $stored = $this->links->chunk($this->upload($link, $upload), (int) $index, (string) $request->header('Upload-Chunk-Sha256', ''), $body);

            return response()->json($this->links->state($stored));
        });
    }

    /** POST /r/{token}/files/{upload}/complete: `{"status": "ready"}`, or what is still missing. */
    public function complete(string $token, string $upload): JsonResponse
    {
        return $this->forLink($token, fn (RequestLink $link): JsonResponse => response()->json($this->links->complete($this->upload($link, $upload))));
    }

    /**
     * Runs the action for a usable link, as its workspace; unknown, revoked and expired links are the same 404.
     *
     * @param  Closure(RequestLink): JsonResponse  $action
     */
    private function forLink(string $token, Closure $action): JsonResponse
    {
        $link = $this->links->usable($token);
        if ($link === null) {
            return response()->json(['message' => 'This link is no longer available.', 'code' => 'not_found'], 404, self::HEADERS);
        }
        try {
            return CurrentWorkspace::runAs($link->workspace_id, fn (): JsonResponse => $action($link));
        } catch (ApiError $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->apiCode(), ...$e->extra()], $e->getStatusCode(), self::HEADERS);
        } catch (HttpExceptionInterface $e) {
            // Fixed texts from the chunk checks (too large, already complete); never a trace.
            return response()->json(['message' => $e->getMessage() !== '' ? $e->getMessage() : 'The upload was refused.', 'code' => 'upload_refused'], $e->getStatusCode(), self::HEADERS);
        }
    }

    private function upload(RequestLink $link, string $id): RequestUpload
    {
        $upload = $link->uploads()->find($id);
        if (! $upload instanceof RequestUpload) {
            throw new ApiError(404, 'not_found', 'This upload is not here.');
        }

        return $upload;
    }
}
