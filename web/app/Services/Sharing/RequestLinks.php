<?php

declare(strict_types=1);

namespace App\Services\Sharing;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\SttUseCase;
use App\Enums\UploadStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use App\Models\RequestLink;
use App\Models\RequestUpload;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChunkedUploadService;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\ConsoleUploads;
use App\Services\Data\CompleteOutcome;
use App\Services\SettingsRepository;
use App\Services\Storage\RecordingFiles;
use App\Services\Workspaces\PlanLimits;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Throwable;
use ZipArchive;

/**
 * Request links (docs/API.md "Request links", since v1.5). Anyone with the link sends files on `/r/{token}` without an
 * account. Sound and video (by their content, not only their name) become recordings of the link's maker, in its
 * project or the Inbox, titled from the file name and marked `requested`; with a project, PDF, Word, text and images
 * go to its Files. Everything else is refused with a plain message, as are files over the link's size, more files
 * than it takes, and anything once it is revoked or expired. Uploads come in chunks like the console's.
 */
final class RequestLinks
{
    public const DEFAULT_TITLE = 'Send me your files';

    public const MAX_FILES = 100;

    /** Documents and images a project's Files take from a request link, by extension, as stored. */
    public const DOCUMENTS = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public const REFUSED = 'This link takes sound and video files (and, for a project, PDF, Word, text and image files). This file is none of them.';

    public const NEEDS_PROJECT = 'This link takes sound and video files only.';

    /**
     * An upload that has received nothing for this long is abandoned: it no longer counts toward the link's files, and
     * TempFileSweeper ends it (sooner once its link is revoked or expired, as nobody can resume it then).
     */
    public const ABANDONED_HOURS = 24;

    public const ABANDONED = 'This file was not finished. Send it again.';

    /** Bytes of the first chunk looked at to tell what a file really is. */
    private const SNIFF_BYTES = 64 * 1024;

    /** Longer than finishing a file takes, so the lock outlives only a request that died while holding it. */
    private const COMPLETE_LOCK_SECONDS = 900;

    public function __construct(private readonly ChunkedUploadService $uploads) {}

    // ---- Links -----------------------------------------------------------------------------------------------------

    /** @return array{link: RequestLink, url: string} */
    public function create(int $workspaceId, int $userId, ?Client $project, ?string $title, ?Carbon $expiresAt, int $maxFiles, int $maxMb): array
    {
        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new ApiError(422, 'validation_failed', 'Choose an expiry in the future.', ['errors' => ['expires_at' => ['Choose an expiry in the future.']]]);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $link = RequestLink::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'client_id' => $project?->id,
            'token_hash' => hash('sha256', $token),
            'title' => mb_substr(trim((string) $title) !== '' ? trim((string) $title) : self::DEFAULT_TITLE, 0, 200),
            'expires_at' => $expiresAt,
            'max_files' => max(1, min(self::MAX_FILES, $maxFiles)),
            'max_mb' => max(1, min(self::maxMegabytes(), $maxMb)),
        ]);

        return ['link' => $link, 'url' => self::url($token)];
    }

    /** The largest `max_mb` a link may have: the size of the largest recording the server takes. */
    public static function maxMegabytes(): int
    {
        return max(1, intdiv((int) config('echoledger.max_recording_bytes'), 1024 * 1024));
    }

    public static function url(string $token): string
    {
        return url('/r/'.$token);
    }

    /**
     * A link that can take files now, found by its token whoever is signed in; null for anything else. Since v1.5
     * also null once its person no longer records in its workspace (a viewer now, or no longer in it), and while their
     * account is in its 7-day deletion grace (it takes files again if they keep the account).
     */
    public function usable(string $token): ?RequestLink
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $link = RequestLink::acrossPeople()->where('token_hash', hash('sha256', $token))->first();
        if (! $link instanceof RequestLink || ! $link->isActive()) {
            return null;
        }
        if (User::query()->whereKey($link->user_id)->whereNotNull('deletes_at')->exists()) {
            return null;
        }
        $role = DB::table('workspace_user')->where('workspace_id', $link->workspace_id)->where('user_id', $link->user_id)->value('role');

        return is_string($role) && WorkspaceRole::tryFrom($role)?->records() === true ? $link : null;
    }

    public function revoke(RequestLink $link): void
    {
        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now()]);
        }
    }

    /** The link's project, when it still has one in its workspace. */
    public function project(RequestLink $link): ?Client
    {
        $project = $link->client;

        return $project instanceof Client && $project->workspace_id === $link->workspace_id && $project->user_id === $link->user_id ? $project : null;
    }

    // ---- Uploads ---------------------------------------------------------------------------------------------------

    /**
     * Starts one file, or picks up its unfinished upload (the same file on the same link, not yet abandoned). Refuses
     * types the link does not take, files over its size, more files than it takes (abandoned uploads not counted) and
     * files the plan's storage has no room for (counting what is still being sent).
     *
     * @param  array{name: string, size: int, sha256: string, type?: string|null}  $file
     */
    public function start(RequestLink $link, array $file): RequestUpload
    {
        $sha = strtolower($file['sha256']);
        $size = (int) $file['size'];
        $unfinished = $link->uploads()->where('sha256', $sha)->where('size_bytes', $size)->where('status', 'uploading')
            ->where('updated_at', '>=', now()->subHours(self::ABANDONED_HOURS))->first();
        if ($unfinished instanceof RequestUpload) {
            return $unfinished;
        }
        $this->assertFilesLeft($link, 0);
        if ($size > $link->max_mb * 1024 * 1024) {
            throw new ApiError(413, 'too_large', "Files can be at most {$link->max_mb} MB here.");
        }
        $audioMime = ConsoleUploads::storedMime($file['name'], $file['type'] ?? null);
        $document = $audioMime === null ? self::documentType($file['name'], $file['type'] ?? null) : null;
        if ($audioMime === null && $document === null) {
            throw new ApiError(422, 'unsupported_type', self::REFUSED);
        }
        $project = $this->project($link);
        if ($document !== null && $project === null) {
            throw new ApiError(422, 'unsupported_type', self::NEEDS_PROJECT);
        }
        if ($document !== null && $size > ClientWorkspace::MAX_FILE_BYTES) {
            throw new ApiError(413, 'too_large', 'Documents and images can be at most '.intdiv(ClientWorkspace::MAX_FILE_BYTES, 1024 * 1024).' MB.');
        }
        $this->assertRoom($link, $size);
        // The last part of a path, without control or direction-changing characters (they can make a name read as another).
        $name = (string) preg_replace('/\p{C}+/u', '', basename(str_replace('\\', '/', $file['name'])));
        $name = mb_substr(trim($name), 0, 255) ?: 'File';

        return DB::transaction(function () use ($link, $project, $audioMime, $name, $size, $sha): RequestUpload {
            // One start at a time in a workspace, and the checks run again once the new upload is in: two senders at
            // once cannot pass the link's number of files or the plan's storage together (the refusal rolls it back).
            Workspace::query()->whereKey($link->workspace_id)->lockForUpdate()->first();
            $upload = $this->newUpload($link, $project, $audioMime, $name, $size, $sha);
            $this->assertFilesLeft($link, 1);
            $this->assertRoom($link, 0);

            return $upload;
        });
    }

    /** `422 too_many_files` when the link's files (finished or still coming, $mine of them this request's) are all taken. */
    private function assertFilesLeft(RequestLink $link, int $mine): void
    {
        $taken = $link->uploads()->where(fn ($q) => $q->where('status', 'ready')
            ->orWhere(fn ($q) => $q->where('status', 'uploading')->where('updated_at', '>=', now()->subHours(self::ABANDONED_HOURS))))->count();
        if ($taken - $mine >= $link->max_files) {
            throw new ApiError(422, 'too_many_files', 'This link takes at most '.$link->max_files.($link->max_files === 1 ? ' file.' : ' files.'));
        }
    }

    /**
     * `403 storage_limit_reached` when the plan's storage cannot also hold $incoming bytes and every file still being
     * sent through the workspace's request links (they are stored once they finish).
     */
    private function assertRoom(RequestLink $link, int $incoming): void
    {
        $links = RequestLink::acrossPeople()->where('workspace_id', $link->workspace_id)->select('id');
        $coming = (int) RequestUpload::query()->whereIn('request_link_id', $links)->where('status', 'uploading')
            ->where('updated_at', '>=', now()->subHours(self::ABANDONED_HOURS))
            // A recording already put together is counted as stored.
            ->where(fn ($q) => $q->whereNull('recording_id')->orWhereIn('recording_id', Recording::query()->whereNull('stored_path')->select('id')))
            ->sum('size_bytes');
        try {
            app(PlanLimits::class)->assertStorage($link->workspace_id, $coming + $incoming);
        } catch (ApiError) {
            // The plan's own wording asks the owner to raise a setting; the sender only needs to know it did not go.
            throw new ApiError(403, 'storage_limit_reached', 'This link cannot take more files right now.');
        }
    }

    /** The upload's row and, for sound or video, its recording waiting for chunks. */
    private function newUpload(RequestLink $link, ?Client $project, ?string $audioMime, string $name, int $size, string $sha): RequestUpload
    {
        $chunkSize = ChunkedUploadService::advertisedChunkSize();
        if ($audioMime === null) {
            return $link->uploads()->create([
                'kind' => 'file', 'name' => $name, 'size_bytes' => $size, 'sha256' => $sha, 'status' => 'uploading',
                'chunk_size_bytes' => $chunkSize, 'total_chunks' => max(1, (int) ceil($size / $chunkSize)), 'received_chunks' => [],
            ]);
        }
        $session = CaptureSession::acrossPeople()->create([
            'workspace_id' => $link->workspace_id,
            'user_id' => $link->user_id,
            'device_id' => null,
            'client_id' => $project?->id,
            'origin' => CaptureSession::ORIGIN_REQUESTED,
            'client_ref' => (string) Str::uuid(),
            'source_device' => SourceDevice::Web,
            'source_app' => SourceApp::VoiceNote,
            'title' => ConsoleUploads::titleFromFileName($name),
            'started_at' => now(),
            'ended_at' => now(),
            'status' => SessionStatus::Uploading,
            'final_provider' => $this->providerFor($project),
        ]);
        $recording = $this->uploads->createRecording($session, [
            'role' => RecordingRole::Primary->value, 'mime' => $audioMime, 'codec' => null, 'channels' => 1, 'sample_rate' => null,
            'duration_ms' => null, 'size_bytes' => $size, 'sha256' => $sha,
        ], $chunkSize);

        return $link->uploads()->create([
            'kind' => 'recording', 'name' => $name, 'size_bytes' => $size, 'sha256' => $sha, 'status' => 'uploading',
            'recording_id' => $recording->id, 'chunk_size_bytes' => $chunkSize, 'total_chunks' => $recording->total_chunks,
        ]);
    }

    /**
     * Stores one chunk. The first chunk decides by its content whether the file is what its name says; one that is not
     * ends the upload (`422 unsupported_type`).
     *
     * @param  resource  $body
     */
    public function chunk(RequestUpload $upload, int $index, string $sha256, $body): RequestUpload
    {
        if ($upload->status !== 'uploading') {
            throw $upload->status === 'ready'
                ? new ApiError(409, 'upload_complete', 'This file is already sent.')
                : new ApiError(409, 'upload_finished', $upload->error ?? 'This file was not taken.');
        }
        if ($upload->kind === 'recording') {
            $recording = $this->recordingOf($upload);
            $this->uploads->storeChunk($recording, $index, $sha256, $body);
            if ($index === 0 && ! ConsoleUploads::firstChunkIsMedia($recording)) {
                $this->refuse($upload, self::REFUSED);
            }
            // The chunks are the recording's; the upload's own time says it is still alive (ABANDONED_HOURS).
            $upload->touch();

            return $upload->refresh();
        }

        if ($index < 0 || $index >= $upload->total_chunks) {
            throw new ApiError(422, 'validation_failed', 'Chunk index out of range.');
        }
        $expected = $index === $upload->total_chunks - 1 ? $upload->size_bytes - $index * $upload->chunk_size_bytes : $upload->chunk_size_bytes;
        $bytes = (string) stream_get_contents($body, $expected + 1);
        if (strlen($bytes) !== $expected || ! hash_equals(strtolower($sha256), hash('sha256', $bytes))) {
            throw new ApiError(422, 'validation_failed', 'The chunk does not match its size or checksum. Send it again.');
        }
        app(RecordingFiles::class)->putPart($upload->partsDirectory().'/'.$index.'.part', $bytes);
        if ($index === 0 && self::sniffDocument(substr($bytes, 0, self::SNIFF_BYTES), $upload->name) === null) {
            $this->refuse($upload, self::REFUSED);
        }

        return DB::transaction(function () use ($upload, $index): RequestUpload {
            // Chunks sent at once each add themselves to the list as it is stored now, not as their request read it.
            $locked = RequestUpload::query()->lockForUpdate()->findOrFail($upload->id);
            $received = array_values(array_unique([...($locked->received_chunks ?? []), $index]));
            sort($received);
            // A chunk sent again changes nothing but still keeps the upload alive (ABANDONED_HOURS).
            $locked->fill(['received_chunks' => $received])->touch();

            return $locked;
        });
    }

    /**
     * Finishes a file once every chunk is in: a recording is assembled, verified, encrypted and transcribed as usual; a
     * document is verified, checked again as a whole and added to the project's Files. One finish at a time per
     * upload: a `complete` sent again while one runs is `409 upload_finished` (a document would be added twice, and
     * each copy read and counted), and one sent after it finds the file done.
     *
     * @return array{status: string, missing_chunks?: list<int>}
     */
    public function complete(RequestUpload $upload): array
    {
        $lock = Cache::lock('request-complete:'.$upload->id, self::COMPLETE_LOCK_SECONDS);
        if (! $lock->get()) {
            throw new ApiError(409, 'upload_finished', 'This file is already being finished.');
        }
        try {
            return $this->finish($upload->refresh());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{status: string, missing_chunks?: list<int>}
     */
    private function finish(RequestUpload $upload): array
    {
        if ($upload->status === 'ready') {
            return ['status' => 'ready'];
        }
        if ($upload->status !== 'uploading') {
            throw new ApiError(409, 'upload_finished', $upload->error ?? 'This file was not taken.');
        }
        if ($upload->kind === 'recording') {
            $outcome = $this->uploads->markComplete($this->recordingOf($upload));

            return match ($outcome->state) {
                CompleteOutcome::INCOMPLETE => throw new ApiError(422, 'upload_incomplete', 'Upload is incomplete.', ['missing_chunks' => $outcome->missing]),
                CompleteOutcome::FAILED => throw new ApiError(422, 'verification_failed', ChunkedUploadService::VERIFICATION_FAILED),
                default => $this->received($upload),
            };
        }

        // Stored by a request that died before it could say so: the file must not be added twice.
        if ($upload->client_file_id !== null) {
            return $this->received($upload);
        }
        $missing = $this->missingParts($upload);
        if ($missing !== []) {
            throw new ApiError(422, 'upload_incomplete', 'Upload is incomplete.', ['missing_chunks' => $missing]);
        }
        $plain = $this->uploads->tempPath('request');
        try {
            $this->assemble($upload, $plain);
            $mime = self::sniffDocument((string) file_get_contents($plain, false, null, 0, self::SNIFF_BYTES), $upload->name);
            $word = $mime === self::DOCUMENTS['docx'] ? self::wordBodySize($plain) : null;
            if ($mime === null || ($mime === self::DOCUMENTS['docx'] && $word === null)) {
                $this->refuse($upload, self::REFUSED);
            }
            if ($word !== null && $word > ClientWorkspace::MAX_WORD_BODY_BYTES) {
                $this->refuse($upload, ClientWorkspace::WORD_TOO_LARGE, 413, 'too_large');
            }
            $project = $this->project($upload->link);
            if ($project === null) {
                $this->refuse($upload, self::NEEDS_PROJECT);
            }
            $file = app(ClientWorkspace::class)->storeFile($project, $plain, $upload->name, (string) $mime, $upload->size_bytes, $upload->link->user_id);
            $upload->update(['client_file_id' => $file->id]);
        } finally {
            @unlink($plain);
        }
        // Only now. A refusal and a file that fails its check remove the parts themselves; after any other failure (the
        // bucket's, say) they stay, and `complete` can be sent again.
        app(RecordingFiles::class)->eraseFolder($upload->partsDirectory());

        return $this->received($upload);
    }

    /**
     * The chunks of a document not on the list or not stored: parts on the server's own disk are not moved when the files
     * go to a bucket (docs/DEPLOY_VPS.md), and those are asked for again, as a recording's are.
     *
     * @return list<int>
     */
    private function missingParts(RequestUpload $upload): array
    {
        $received = array_flip($upload->received_chunks ?? []);
        $stored = array_flip(app(RecordingFiles::class)->namesIn($upload->partsDirectory()));

        return array_values(array_filter(range(0, $upload->total_chunks - 1), static fn (int $index): bool => ! isset($received[$index], $stored[$index.'.part'])));
    }

    /**
     * Where the browser is in an upload (a resumed one sends only the missing chunks).
     *
     * @return array<string, mixed>
     */
    public function state(RequestUpload $upload): array
    {
        $received = $upload->kind === 'recording' ? $this->recordingOf($upload)->received_chunks : ($upload->received_chunks ?? []);

        return [
            'upload_id' => $upload->id,
            'status' => $upload->status,
            'chunk_size' => $upload->chunk_size_bytes,
            'total_chunks' => $upload->total_chunks,
            'missing_chunks' => array_values(array_diff(range(0, max(0, $upload->total_chunks - 1)), $received)),
        ];
    }

    /** @return array{status: string} */
    private function received(RequestUpload $upload): array
    {
        DB::transaction(function () use ($upload): void {
            $upload->update(['status' => 'ready']);
            RequestLink::query()->withoutGlobalScopes()->whereKey($upload->request_link_id)->increment('received');
        });

        return ['status' => 'ready'];
    }

    /**
     * Ends an upload nobody finished (TempFileSweeper): a recording still uploading is deleted, which erases its parts
     * at once (CaptureSession::booted), and a document's parts go. One whose every chunk came in, but whose request
     * died before it said so, counts as received instead.
     */
    public function abandon(RequestUpload $upload): void
    {
        $recording = $upload->kind === 'recording' && $upload->recording_id !== null ? Recording::query()->find($upload->recording_id) : null;
        if ($recording !== null && in_array($recording->upload_status, [UploadStatus::Assembling, UploadStatus::Ready], true)) {
            // Assembling is finished by recordings:recover; a later sweep sees it ready.
            if ($recording->upload_status === UploadStatus::Ready) {
                $this->received($upload);
            }

            return;
        }
        $session = $recording === null ? null
            : CaptureSession::acrossPeople()->whereKey($recording->capture_session_id)->first();
        if ($session !== null && $session->status === SessionStatus::Uploading) {
            $session->delete();
        }
        app(RecordingFiles::class)->eraseFolder($upload->partsDirectory());
        $upload->update(['status' => 'failed', 'error' => self::ABANDONED]);
    }

    /** Ends an upload that is not what the link takes: what was sent of it is deleted. */
    private function refuse(RequestUpload $upload, string $message, int $status = 422, string $code = 'unsupported_type'): never
    {
        if ($upload->kind === 'recording' && $upload->recording_id !== null) {
            // Still uploading: deleting the session erases the parts at once (CaptureSession::booted).
            Recording::query()->find($upload->recording_id)?->captureSession()->first()?->delete();
        }
        app(RecordingFiles::class)->eraseFolder($upload->partsDirectory());
        $upload->update(['status' => 'refused', 'error' => $message]);

        throw new ApiError($status, $code, $message);
    }

    private function recordingOf(RequestUpload $upload): Recording
    {
        $recording = Recording::query()->find($upload->recording_id);
        if (! $recording instanceof Recording) {
            throw new ApiError(404, 'not_found', 'This upload is no longer here.');
        }

        return $recording;
    }

    private function assemble(RequestUpload $upload, string $plain): void
    {
        $files = app(RecordingFiles::class);
        $out = fopen($plain, 'wb');
        $hash = hash_init('sha256');
        $size = 0;
        try {
            for ($i = 0; $i < $upload->total_chunks; $i++) {
                $files->readPart($upload->partsDirectory().'/'.$i.'.part', static function ($part) use ($out, $hash, &$size): void {
                    while (! feof($part)) {
                        $buffer = (string) fread($part, 1024 * 1024);
                        $size += strlen($buffer);
                        hash_update($hash, $buffer);
                        fwrite($out, $buffer);
                    }
                });
            }
        } finally {
            fclose($out);
        }
        if ($size !== $upload->size_bytes || ! hash_equals($upload->sha256, hash_final($hash))) {
            $upload->update(['received_chunks' => []]);
            $files->eraseFolder($upload->partsDirectory());
            throw new ApiError(422, 'verification_failed', ChunkedUploadService::VERIFICATION_FAILED);
        }
    }

    /** The type a document is stored as, from its extension or the browser's type; null when the link takes no such file. */
    public static function documentType(string $name, ?string $browserType): ?string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $type = strtolower(trim((string) $browserType));

        return self::DOCUMENTS[$extension] ?? (in_array($type, self::DOCUMENTS, true) ? $type : null);
    }

    /**
     * What a document really is, from its first bytes: PDF, plain text, PNG, JPEG, WebP, or a ZIP named .docx (a Word
     * file, checked whole when it is complete). Null for anything else.
     */
    public static function sniffDocument(string $head, string $name): ?string
    {
        if ($head === '') {
            return null;
        }
        $type = strtolower((string) (new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer($head));
        $word = self::DOCUMENTS['docx'];

        return match (true) {
            in_array($type, ['application/pdf', 'text/plain', 'image/png', 'image/jpeg', 'image/webp'], true) => $type,
            str_starts_with($head, "PK\x03\x04") && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'docx'
                && in_array($type, ['application/zip', $word, 'application/octet-stream'], true) => $word,
            default => null,
        };
    }

    /**
     * A Word file is a ZIP with the document's body in `word/document.xml`: how many bytes that body unpacks to, or
     * null when the file is no Word file.
     */
    private static function wordBodySize(string $path): ?int
    {
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }
            $size = ClientWorkspace::wordBodySize($zip);
            $zip->close();

            return $size;
        } catch (Throwable) {
            return null;
        }
    }

    /** The engine the project's or the default language routes to (the final transcript routes again when it starts). */
    private function providerFor(?Client $project): string
    {
        $language = LanguageChoice::normalize($project?->language) ?? LanguageChoice::normalize(app(SettingsRepository::class)->get('default_language'));

        return app(LanguageRouter::class)->final($language, SttUseCase::VoiceNote)->provider->value;
    }
}
