<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\UploadStatus;
use App\Exceptions\ApiError;
use App\Jobs\AssembleRecordingJob;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Recording;
use App\Services\Data\CompleteOutcome;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resumable raw-body chunk uploads (docs/API.md "Sessions, recordings, uploads", PLAN.md D4).
 */
final class ChunkedUploadService
{
    /** Resolved lazily so endpoints that never touch file contents work even before the key is configured. */
    private function cipher(): RecordingCipher
    {
        return app(RecordingCipher::class);
    }

    /**
     * Safe chunk size for raw octet-stream bodies: only post_max_size applies (not upload_max_filesize).
     * Must be computed inside an HTTP request — CLI ini values differ.
     */
    public static function advertisedChunkSize(): int
    {
        $limit = static function (string $key): int {
            $value = ini_parse_quantity((string) ini_get($key));

            return $value > 0 ? $value : PHP_INT_MAX;
        };
        $raw = $limit('post_max_size') - 64 * 1024;
        $max = (int) config('echoledger.max_chunk_bytes', 5 * 1024 * 1024);
        $step = 256 * 1024;

        return max($step, min($max, intdiv($raw, $step) * $step));
    }

    /** @param array<string, mixed> $artifact validated artifact (docs/API.md) */
    public function createRecording(CaptureSession $session, array $artifact, int $chunkSize): Recording
    {
        $size = (int) $artifact['size_bytes'];
        if ($size > (int) config('echoledger.max_recording_bytes')) {
            throw ValidationException::withMessages(['artifacts' => 'Recording exceeds the maximum allowed size.']);
        }

        return $session->recordings()->create([
            'role' => $artifact['role'],
            'mime' => strtolower((string) $artifact['mime']),
            'codec' => $artifact['codec'] ?? null,
            'channels' => (int) ($artifact['channels'] ?? 1),
            'sample_rate' => $artifact['sample_rate'] ?? null,
            'duration_ms' => $artifact['duration_ms'] ?? null,
            'declared_size_bytes' => $size,
            'sha256' => strtolower((string) $artifact['sha256']),
            'chunk_size_bytes' => $chunkSize,
            'total_chunks' => max(1, (int) ceil($size / $chunkSize)),
            'received_chunks' => [],
            'upload_status' => UploadStatus::Pending,
        ]);
    }

    /** A device may keep at most this many recordings waiting for chunks (docs/API.md `too_many_open_uploads`). */
    public const MAX_OPEN_UPLOADS = 50;

    /** Refuses new upload slots when the device already has too many unfinished ones. */
    public function assertOpenUploadCapacity(Device $device, int $adding): void
    {
        $open = Recording::query()
            ->whereIn('upload_status', [UploadStatus::Pending->value, UploadStatus::Uploading->value])
            ->whereHas('captureSession', fn ($q) => $q->where('device_id', $device->id))
            ->count();

        if ($open + $adding > self::MAX_OPEN_UPLOADS) {
            throw new ApiError(409, 'too_many_open_uploads', 'Too many unfinished uploads. Finish or delete some first.');
        }
    }

    public function expectedChunkLength(Recording $recording, int $index): int
    {
        if ($index < $recording->total_chunks - 1) {
            return $recording->chunk_size_bytes;
        }

        return $recording->declared_size_bytes - $recording->chunk_size_bytes * ($recording->total_chunks - 1);
    }

    /**
     * Stores one chunk idempotently. The body is streamed to disk, hashed, size-checked, then atomically renamed.
     *
     * @param  resource  $body
     */
    public function storeChunk(Recording $recording, int $index, string $expectedSha256, $body): Recording
    {
        // A failed upload accepts chunks again: the client sends the whole file from chunk 0 (docs/API.md).
        if (in_array($recording->upload_status, [UploadStatus::Assembling, UploadStatus::Ready], true)) {
            throw new ConflictHttpException('Recording upload is already complete.');
        }
        if ($index < 0 || $index >= $recording->total_chunks) {
            throw ValidationException::withMessages(['index' => 'Chunk index out of range.']);
        }
        $expectedSha256 = strtolower(trim($expectedSha256));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw ValidationException::withMessages(['sha256' => 'Upload-Chunk-Sha256 must be a hex SHA-256.']);
        }

        $disk = Storage::disk('recordings');
        $disk->makeDirectory($recording->partsDirectory());
        $final = $disk->path($recording->partsDirectory().'/'.$index.'.part');
        $tmp = $final.'.'.bin2hex(random_bytes(6)).'.tmp';

        $limit = $this->expectedChunkLength($recording, $index);
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot write upload chunk.');
        }
        $hash = hash_init('sha256');
        $length = 0;
        try {
            while (! feof($body)) {
                $buffer = fread($body, 1024 * 1024);
                if ($buffer === false || $buffer === '') {
                    break;
                }
                $length += strlen($buffer);
                if ($length > $limit) {
                    throw new HttpException(413, 'Chunk is larger than expected.');
                }
                hash_update($hash, $buffer);
                fwrite($out, $buffer);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);

        if ($length !== $limit) {
            @unlink($tmp);
            throw ValidationException::withMessages(['chunk' => "Chunk {$index} must be exactly {$limit} bytes (got {$length})."]);
        }
        if (! hash_equals($expectedSha256, hash_final($hash))) {
            @unlink($tmp);
            throw ValidationException::withMessages(['sha256' => 'Chunk checksum mismatch.']);
        }
        if (! rename($tmp, $final)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot finalize upload chunk.');
        }

        return DB::transaction(function () use ($recording, $index): Recording {
            /** @var Recording $locked */
            $locked = Recording::query()->lockForUpdate()->findOrFail($recording->id);
            // The first chunk after a failure starts afresh: nothing received earlier is still on disk.
            $earlier = $locked->upload_status === UploadStatus::Failed ? [] : $locked->received_chunks;
            $received = array_values(array_unique([...$earlier, $index]));
            sort($received);
            $locked->update(['received_chunks' => $received, 'upload_status' => UploadStatus::Uploading, 'error' => null]);
            $locked->captureSession->update(['status' => SessionStatus::Uploading]);

            return $locked;
        });
    }

    /** @return list<int> */
    public function missingChunks(Recording $recording): array
    {
        $disk = Storage::disk('recordings');
        $missing = [];
        for ($i = 0; $i < $recording->total_chunks; $i++) {
            if (! in_array($i, $recording->received_chunks, true) || ! $disk->exists($recording->partsDirectory().'/'.$i.'.part')) {
                $missing[] = $i;
            }
        }

        return $missing;
    }

    /**
     * Recordings up to this size are assembled + transcribed inline on the `complete` request (seconds, not the
     * up-to-a-minute wait for the next scheduled queue run). ~15 min of stereo FLAC. Larger ones use the queue.
     */
    public const SYNC_ASSEMBLE_MAX_BYTES = 20 * 1024 * 1024;

    /** `upload.error` after the assembled file failed the size or SHA-256 check (docs/API.md `verification_failed`). */
    public const VERIFICATION_FAILED = 'The uploaded file did not match its checksum. Send it again.';

    /** `upload.error` when an interrupted assembly cannot be finished from what is left on disk. */
    public const NOT_FINISHED = 'Upload could not be finished. Send the file again.';

    /**
     * Idempotent `complete`: a repeat while assembling or after a failure (before new chunks arrive) gives the same
     * answer again.
     */
    public function markComplete(Recording $recording): CompleteOutcome
    {
        $known = self::settledOutcome($recording);
        if ($known !== null) {
            return $known;
        }
        $missing = $this->missingChunks($recording);
        if ($missing !== []) {
            return CompleteOutcome::incomplete($missing);
        }
        $recording->update(['upload_status' => UploadStatus::Assembling]);

        $queued = true;
        if ($recording->declared_size_bytes <= self::SYNC_ASSEMBLE_MAX_BYTES) {
            try {
                Bus::dispatchNow(new AssembleRecordingJob($recording->id));
                $queued = false;
            } catch (\Throwable $e) {
                report($e); // fall through to the queue so the recording still gets processed
            }
        }
        if ($queued) {
            AssembleRecordingJob::dispatch($recording->id);
        }

        return self::settledOutcome($recording->refresh()) ?? CompleteOutcome::assembling();
    }

    /** The answer for a recording that is past the chunk stage, or null while chunks are still expected. */
    private static function settledOutcome(Recording $recording): ?CompleteOutcome
    {
        return match (true) {
            $recording->upload_status === UploadStatus::Ready => CompleteOutcome::ready(),
            $recording->upload_status === UploadStatus::Assembling => CompleteOutcome::assembling(),
            $recording->upload_status === UploadStatus::Failed && $recording->received_chunks === [] => CompleteOutcome::failed(),
            default => null,
        };
    }

    /**
     * Marks the upload failed and discards its parts, so the client sends the whole file again from chunk 0
     * (`received_chunks` becomes empty).
     */
    public function discard(Recording $recording, string $error): void
    {
        $recording->update(['upload_status' => UploadStatus::Failed, 'error' => $error, 'received_chunks' => []]);
        $disk = Storage::disk('recordings');
        if ($disk->directoryExists($recording->partsDirectory())) {
            $disk->deleteDirectory($recording->partsDirectory());
        }
    }

    /** Called by AssembleRecordingJob: concatenate → verify size + SHA-256 → encrypt → delete parts. */
    public function assemble(Recording $recording): void
    {
        $disk = Storage::disk('recordings');
        $plainPath = $this->tempPath('assemble');
        $out = fopen($plainPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot create assembly file.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            for ($i = 0; $i < $recording->total_chunks; $i++) {
                $part = fopen($disk->path($recording->partsDirectory().'/'.$i.'.part'), 'rb');
                if ($part === false) {
                    throw new RuntimeException("Missing chunk {$i}.");
                }
                while (! feof($part)) {
                    $buffer = (string) fread($part, 1024 * 1024);
                    $size += strlen($buffer);
                    hash_update($hash, $buffer);
                    fwrite($out, $buffer);
                }
                fclose($part);
            }
            fclose($out);

            if ($size !== $recording->declared_size_bytes || ! hash_equals($recording->sha256, hash_final($hash))) {
                $this->discard($recording, self::VERIFICATION_FAILED);

                return;
            }

            $this->storeEncrypted($recording, $plainPath);
            $disk->deleteDirectory($recording->partsDirectory());
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($plainPath);
        }
    }

    /** Encrypts a verified plaintext file into the recordings disk and marks the recording ready. */
    public function storeEncrypted(Recording $recording, string $plainPath): void
    {
        $disk = Storage::disk('recordings');
        $relative = self::storedPathFor($recording);
        $disk->makeDirectory(dirname($relative));
        $this->cipher()->encryptFile($plainPath, $disk->path($relative), $recording->id);

        $recording->update([
            'stored_path' => $relative,
            'stored_size_bytes' => filesize($disk->path($relative)) ?: null,
            'upload_status' => UploadStatus::Ready,
            'assembled_at' => now(),
            'error' => null,
        ]);
    }

    /**
     * Finishes an interrupted assembly from the encrypted file it already wrote (`recordings:recover`, the parts are
     * gone). The file is decrypted as a stream and kept only when its size and SHA-256 match the declaration.
     *
     * @return bool|null true: adopted, the recording is ready; false: the file did not verify and was deleted;
     *                   null: there is no such file
     */
    public function adoptStoredFile(Recording $recording): ?bool
    {
        $disk = Storage::disk('recordings');
        $relative = $recording->stored_path ?? self::storedPathFor($recording);
        if (! $disk->exists($relative)) {
            return null;
        }
        $cipher = $this->cipher(); // a missing key must stop here, not count as a bad file

        try {
            $digest = $cipher->digest($disk->path($relative), $recording->id);
            $valid = $digest['size'] === $recording->declared_size_bytes && hash_equals($recording->sha256, $digest['sha256']);
        } catch (RuntimeException) {
            $valid = false; // cut short or damaged while it was being written
        }
        if (! $valid) {
            $disk->delete($relative);

            return false;
        }

        $recording->update([
            'stored_path' => $relative,
            'stored_size_bytes' => filesize($disk->path($relative)) ?: null,
            'upload_status' => UploadStatus::Ready,
            'assembled_at' => now(),
            'error' => null,
        ]);
        if ($disk->directoryExists($recording->partsDirectory())) {
            $disk->deleteDirectory($recording->partsDirectory());
        }

        return true;
    }

    /** Where the encrypted file of a recording lives on the recordings disk (known before `stored_path` is saved). */
    public static function storedPathFor(Recording $recording): string
    {
        return 'files/'.$recording->created_at->format('Y/m').'/'.$recording->id.'.elr';
    }

    /** Decrypts a ready recording to a private temp file. The caller MUST unlink it in `finally`. */
    public function decryptToTemp(Recording $recording): string
    {
        if (! $recording->isReady()) {
            throw new RuntimeException("Recording {$recording->id} is not ready.");
        }
        $path = $this->tempPath($recording->role === RecordingRole::Primary ? 'primary' : $recording->role->value);
        $this->cipher()->decryptToFile(Storage::disk('recordings')->path((string) $recording->stored_path), $path, $recording->id);

        return $path;
    }

    public function tempPath(string $label): string
    {
        $dir = (string) config('echoledger.tmp_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$label.'-'.bin2hex(random_bytes(8)).'.bin';
    }
}
