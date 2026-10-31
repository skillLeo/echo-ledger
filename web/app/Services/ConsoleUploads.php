<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\SttUseCase;
use App\Enums\UploadStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use App\Models\User;
use App\Services\Data\CompleteOutcome;
use App\Services\Workspaces\PlanLimits;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * Library → Upload in the console: sound and video files sent from the browser in chunks, through the same
 * chunk / assemble / verify path the devices use (ChunkedUploadService). Each file becomes its own Web recording titled
 * from its file name. The browser hashes the file first, so a file whose sound is already in the workspace's Library
 * is skipped before any of it is sent, and the same file picked again resumes its unfinished upload.
 */
final class ConsoleUploads
{
    /** The file types offered, by extension, with the type each is stored as (a video keeps its container's audio type). */
    public const EXTENSIONS = [
        'opus' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'amr' => 'audio/amr',
        'mp4' => 'audio/mp4',
        'webm' => 'audio/webm',
    ];

    /** Types browsers report for files without a known extension, as stored (container names via canonicalMime). */
    private const BROWSER_TYPES = [
        'application/ogg' => 'audio/ogg',
        'audio/opus' => 'audio/ogg',
        'audio/mp3' => 'audio/mpeg',
        'audio/x-m4a' => 'audio/mp4',
        'audio/m4a' => 'audio/mp4',
        'audio/x-aac' => 'audio/aac',
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/vnd.wave' => 'audio/wav',
        'audio/x-flac' => 'audio/flac',
    ];

    /** Container types a video file is sniffed as; its sound is what gets transcribed. */
    private const VIDEO_CONTAINERS = ['video/mp4', 'video/webm'];

    /** Bytes of the first chunk looked at to tell sound and video files from anything else. */
    private const SNIFF_BYTES = 64 * 1024;

    /** Unfinished console uploads one person may have at a time (devices have the same cap). */
    public const MAX_OPEN_UPLOADS = ChunkedUploadService::MAX_OPEN_UPLOADS;

    public const DUPLICATE_MESSAGE = 'Already in your Library';

    public const NOT_MEDIA_MESSAGE = 'This is not a sound or video file EchoLedger can read.';

    public function __construct(private readonly ChunkedUploadService $uploads) {}

    /**
     * The type a picked file is stored as, from its extension or else the type the browser reported; null when it is
     * neither a sound nor a video file the Library takes.
     */
    public static function storedMime(string $name, ?string $browserType = null): ?string
    {
        $extension = strtolower(pathinfo(self::baseName($name), PATHINFO_EXTENSION));
        $reported = strtolower(trim((string) $browserType));
        $type = self::EXTENSIONS[$extension]
            ?? self::BROWSER_TYPES[$reported]
            ?? RecordingIngestor::canonicalMime($reported);
        $accepted = array_map('strtolower', (array) config('echoledger.accepted_mimes'));

        return $type !== '' && str_starts_with($type, 'audio/') && in_array($type, $accepted, true) ? $type : null;
    }

    /**
     * "WhatsApp_Ptt 2026-10-30 at 11.39.12.opus" → "WhatsApp Ptt 2026-10-30 at 11.39.12": the name without its extension,
     * underscores as spaces, at most 200 characters (the phone's import does the same).
     */
    public static function titleFromFileName(string $name): string
    {
        $name = (string) preg_replace('/\p{C}+/u', '', self::baseName($name));
        $dot = mb_strrpos($name, '.');
        $bare = $dot !== false && $dot > 0 ? mb_substr($name, 0, $dot) : $name;
        $title = trim(mb_substr(trim(str_replace('_', ' ', $bare)), 0, 200));

        return $title === '' ? 'Imported file' : $title;
    }

    /** The last part of a path: browsers send a bare name, but nothing from the client is trusted to be one. */
    private static function baseName(string $name): string
    {
        $parts = explode('/', str_replace('\\', '/', $name));

        return (string) end($parts);
    }

    /**
     * Starts the upload of one file, or picks up the person's unfinished upload of the same file. Throws ApiError
     * 409 `duplicate_recording` when the file's sound is already in the workspace's Library, 403 at a plan limit and
     * 409 `too_many_open_uploads`.
     *
     * @param  array{name: string, size: int, sha256: string, type?: string|null, duration_ms?: int|null, client_id?: string|null, language?: string|null}  $file
     * @return array{recording: Recording, resumed: bool}
     */
    public function start(User $user, int $workspaceId, array $file, int $chunkSize): array
    {
        $mime = self::storedMime($file['name'], $file['type'] ?? null);
        if ($mime === null) {
            throw new ApiError(422, 'unsupported_type', self::NOT_MEDIA_MESSAGE);
        }
        $sha = strtolower($file['sha256']);
        $size = (int) $file['size'];

        // Live recordings only: the undo bin and deleted recordings do not count.
        $matches = Recording::query()->with('captureSession')
            ->where('role', RecordingRole::Primary->value)->where('sha256', $sha)
            ->whereIn('capture_session_id', CaptureSession::query()->where('workspace_id', $workspaceId)->select('id'))
            ->orderBy('created_at')->get();
        $inLibrary = $matches->first(fn (Recording $r): bool => ! $this->isResumable($r, $user, $size));
        if ($inLibrary instanceof Recording) {
            throw new ApiError(409, 'duplicate_recording', self::DUPLICATE_MESSAGE, $this->sessionLink($inLibrary->captureSession));
        }
        $client = $this->client($workspaceId, $file['client_id'] ?? null);
        $unfinished = $matches->first();
        if ($unfinished instanceof Recording) {
            // The batch's client and language apply to the resumed file too.
            $unfinished->captureSession->update(['client_id' => $client?->id, 'language' => LanguageChoice::normalize($file['language'] ?? null)]);

            return ['recording' => $unfinished, 'resumed' => true];
        }

        // The transcript would fail at once: say so before the file is sent, in the words the plan limit uses.
        app(PlanLimits::class)->assertMinutes($workspaceId);
        $this->assertOpenUploadCapacity($user, $workspaceId);

        $recording = DB::transaction(function () use ($user, $workspaceId, $file, $client, $mime, $size, $sha, $chunkSize): Recording {
            $duration = isset($file['duration_ms']) ? (int) $file['duration_ms'] : null;
            $session = CaptureSession::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'device_id' => null,
                'client_id' => $client?->id,
                'client_ref' => (string) Str::uuid(),
                'source_device' => SourceDevice::Web,
                // Imported files are filed like the phone's imports and voice notes.
                'source_app' => SourceApp::VoiceNote,
                'title' => self::titleFromFileName($file['name']),
                'language' => LanguageChoice::normalize($file['language'] ?? null),
                'started_at' => now()->subMilliseconds($duration ?? 0),
                'ended_at' => now(),
                'duration_ms' => $duration,
                'status' => SessionStatus::Uploading,
                'final_provider' => $this->providerFor($file['language'] ?? null, $client),
            ]);

            return $this->uploads->createRecording($session, [
                'role' => RecordingRole::Primary->value,
                'mime' => $mime,
                'codec' => null,
                'channels' => 1,
                'sample_rate' => null,
                'duration_ms' => $duration,
                'size_bytes' => $size,
                'sha256' => $sha,
            ], $chunkSize);
        });

        return ['recording' => $recording, 'resumed' => false];
    }

    /** The same person's unfinished console upload of the same file: picking the file again carries on with it. */
    private function isResumable(Recording $recording, User $user, int $size): bool
    {
        $session = $recording->captureSession;

        return $session->source_device === SourceDevice::Web && $session->device_id === null
            && (int) $session->user_id === (int) $user->id && $recording->declared_size_bytes === $size
            && in_array($recording->upload_status, [UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Failed], true);
    }

    private function assertOpenUploadCapacity(User $user, int $workspaceId): void
    {
        $open = Recording::query()
            ->whereIn('upload_status', [UploadStatus::Pending->value, UploadStatus::Uploading->value])
            ->whereIn('capture_session_id', $this->ownUploads($user, $workspaceId)->select('id'))
            ->count();
        if ($open >= self::MAX_OPEN_UPLOADS) {
            throw new ApiError(409, 'too_many_open_uploads', 'Too many unfinished uploads. Finish or delete some first.');
        }
    }

    /** The workspace's client chosen for the batch; one of another workspace reads as unknown. */
    private function client(int $workspaceId, mixed $clientId): ?Client
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }
        $client = Client::query()->where('workspace_id', $workspaceId)->find((string) $clientId);
        if (! $client instanceof Client) {
            throw new ApiError(422, 'client_not_found', 'Choose one of your clients.', ['errors' => ['client_id' => ['Choose one of your clients.']]]);
        }

        return $client;
    }

    /** The engine the language routes to, as a device's create does (the final transcript routes again when it starts). */
    private function providerFor(?string $language, ?Client $client): string
    {
        $chosen = LanguageChoice::normalize($language) ?? LanguageChoice::normalize($client?->language)
            ?? LanguageChoice::normalize(app(SettingsRepository::class)->get('default_language'));

        return app(LanguageRouter::class)->final($chosen, SttUseCase::VoiceNote)->provider->value;
    }

    /**
     * The person's own unfinished or finished console upload, while its recording is in the Library. Another person's,
     * another workspace's, a device's or a deleted one is null (the routes answer 404).
     */
    public function find(User $user, int $workspaceId, string $id): ?Recording
    {
        $recording = Recording::query()->whereKey($id)
            ->whereIn('capture_session_id', $this->ownUploads($user, $workspaceId)->select('id'))->first();

        return $recording instanceof Recording ? $recording : null;
    }

    /** @return Builder<CaptureSession> */
    private function ownUploads(User $user, int $workspaceId): Builder
    {
        return CaptureSession::query()->where('workspace_id', $workspaceId)->where('user_id', $user->id)
            ->where('source_device', SourceDevice::Web->value)->whereNull('device_id');
    }

    /**
     * Stores one chunk. The first chunk is checked to be a sound or video file before anything else is accepted; one
     * that is not ends the upload (ApiError 422 `not_audio`).
     *
     * @param  resource  $body
     */
    public function storeChunk(Recording $recording, int $index, string $sha256, $body): Recording
    {
        $recording = $this->uploads->storeChunk($recording, $index, $sha256, $body);
        if ($index === 0) {
            $this->assertMedia($recording);
        }

        return $recording;
    }

    /** Assembles, verifies and encrypts the file once every chunk is in; the final transcript then starts. */
    public function complete(Recording $recording): CompleteOutcome
    {
        if (! in_array($recording->upload_status, [UploadStatus::Assembling, UploadStatus::Ready], true)
            && Storage::disk('recordings')->exists($recording->partsDirectory().'/0.part')) {
            $this->assertMedia($recording);
        }

        return $this->uploads->markComplete($recording);
    }

    /** Cancel: an unfinished upload is deleted with what was sent of it. A finished one is deleted from the Library instead. */
    public function cancel(Recording $recording): void
    {
        if (in_array($recording->upload_status, [UploadStatus::Assembling, UploadStatus::Ready], true)) {
            throw new ApiError(409, 'upload_finished', 'This file is already uploaded. Delete the recording in the Library instead.');
        }
        // Still uploading: the session's delete erases the parts at once (CaptureSession::booted).
        $recording->captureSession->delete();
    }

    /**
     * Where the browser is in an upload: the chunks still to send (a resumed upload sends only those).
     *
     * @return array<string, mixed>
     */
    public function state(Recording $recording): array
    {
        $session = $recording->captureSession;

        return [
            'recording_id' => $recording->id,
            'status' => $recording->upload_status->value,
            'error' => $recording->upload_status === UploadStatus::Failed ? $recording->error : null,
            'chunk_size' => $recording->chunk_size_bytes,
            'total_chunks' => $recording->total_chunks,
            'received_chunks' => $recording->received_chunks,
            'missing_chunks' => $this->uploads->missingChunks($recording),
            ...$this->sessionLink($session),
        ];
    }

    /** @return array{session_id: string, title: string, url: string} */
    public function sessionLink(CaptureSession $session): array
    {
        return [
            'session_id' => $session->id,
            'title' => $session->displayTitle(),
            'url' => route('filament.admin.resources.sessions.view', ['record' => $session->id]),
        ];
    }

    /** Refuses (and deletes) an upload whose first bytes are not a sound or video container. */
    private function assertMedia(Recording $recording): void
    {
        $path = Storage::disk('recordings')->path($recording->partsDirectory().'/0.part');
        $head = is_file($path) ? (string) file_get_contents($path, false, null, 0, self::SNIFF_BYTES) : '';
        $sniffed = strtolower((string) (new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer($head));
        $accepted = [...array_map('strtolower', (array) config('echoledger.accepted_mimes')), ...self::VIDEO_CONTAINERS];
        if ($head !== '' && in_array($sniffed, $accepted, true)) {
            return;
        }
        $recording->captureSession->delete();

        throw new ApiError(422, 'not_audio', self::NOT_MEDIA_MESSAGE);
    }
}
