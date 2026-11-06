<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\StorageDeletion;
use App\Services\RecordingCipher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use SplTempFileObject;
use Throwable;

/**
 * Everything that needs a file of the recordings disk as a file. On the server's own disk (the default) that is the
 * file itself, as it always was. On object storage (RECORDINGS_DRIVER=s3) the encrypted file is copied to the temp
 * directory for as long as it is needed, and whatever is to be stored goes up from there; the copies go in `finally`,
 * and TempFileSweeper removes any a killed process left. Encryption is the same on both, with the same file layout.
 *
 * The bucket belongs to someone else, so plaintext never goes there: upload parts, which wait unencrypted on the
 * server's own disk until the whole file is in and verified, are encrypted one by one on the way to a bucket.
 */
final class RecordingFiles
{
    /** The wait before a delete the bucket refused is tried again; it doubles up to MAX_RETRY_MINUTES. */
    public const FIRST_RETRY_MINUTES = 5;

    public const MAX_RETRY_MINUTES = 360;

    private const RETRIES_PER_RUN = 200;

    public function disk(): FilesystemAdapter
    {
        return Storage::disk('recordings');
    }

    /** Whether the files are on the server's own disk. */
    public function isLocal(): bool
    {
        return $this->disk()->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /** A new path in the private temp directory. The caller MUST unlink it in `finally`. */
    public static function tempPath(string $label): string
    {
        $dir = (string) config('echoledger.tmp_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$label.'-'.bin2hex(random_bytes(8)).'.bin';
    }

    /**
     * Encrypts a local plaintext file into the disk.
     *
     * @param  string  $aad  what the file is bound to (RecordingCipher): the record's id
     * @return int bytes the encrypted file takes
     */
    public function encryptInto(string $plainPath, string $relative, string $aad): int
    {
        $disk = $this->disk();
        if ($this->isLocal()) {
            $disk->makeDirectory(dirname($relative));
            $target = $disk->path($relative);
            $this->cipher()->encryptFile($plainPath, $target, $aad);
            clearstatcache(true, $target);

            return (int) filesize($target);
        }

        $encrypted = self::tempPath('store');
        try {
            $this->cipher()->encryptFile($plainPath, $encrypted, $aad);
            $this->upload($encrypted, $relative);

            return (int) filesize($encrypted);
        } finally {
            @unlink($encrypted);
        }
    }

    /** @return int plaintext bytes written */
    public function decryptToFile(string $relative, string $dstPath, string $aad): int
    {
        return $this->withLocalCopy($relative, fn (string $path): int => $this->cipher()->decryptToFile($path, $dstPath, $aad));
    }

    /**
     * @param  resource  $out
     * @return int plaintext bytes written
     */
    public function decryptToStream(string $relative, $out, string $aad): int
    {
        return $this->withLocalCopy($relative, fn (string $path): int => $this->cipher()->decryptToStream($path, $out, $aad));
    }

    public function decryptToMemory(string $relative, string $aad): SplTempFileObject
    {
        return $this->withLocalCopy($relative, fn (string $path): SplTempFileObject => $this->cipher()->decryptToMemory($path, $aad));
    }

    /**
     * Runs $work with a local path of an encrypted file. A failed or cut-short download throws before $work runs, so
     * $work can take a file that does not decrypt for a damaged file, never for a network error.
     *
     * @template T
     *
     * @param  callable(string): T  $work
     * @return T
     */
    public function withLocalCopy(string $relative, callable $work): mixed
    {
        if ($this->isLocal()) {
            return $work($this->disk()->path($relative));
        }

        $copy = self::tempPath('fetch');
        try {
            $this->download($relative, $copy);

            return $work($copy);
        } finally {
            @unlink($copy);
        }
    }

    /**
     * Where a chunk is written before it is checked. Beside its final place on the server's own disk, so the rename
     * that finishes it is atomic; in the temp directory otherwise.
     */
    public function partWorkPath(string $relative): string
    {
        if (! $this->isLocal()) {
            return self::tempPath('part');
        }
        $this->disk()->makeDirectory(dirname($relative));

        return $this->disk()->path($relative).'.'.bin2hex(random_bytes(6)).'.tmp';
    }

    /** Puts a checked chunk in its place; the work file is gone afterwards, also when this fails. */
    public function finishPart(string $workPath, string $relative): void
    {
        try {
            if ($this->isLocal()) {
                if (! rename($workPath, $this->disk()->path($relative))) {
                    throw new RuntimeException('Cannot finalize upload chunk.');
                }

                return;
            }
            $this->encryptInto($workPath, $relative, $relative);
        } finally {
            @unlink($workPath);
        }
    }

    /** Stores a chunk held in memory. */
    public function putPart(string $relative, string $bytes): void
    {
        if ($this->isLocal()) {
            $this->disk()->put($relative, $bytes);

            return;
        }
        $work = self::tempPath('part');
        try {
            if (file_put_contents($work, $bytes) === false) {
                throw new RuntimeException('Cannot write upload chunk.');
            }
            $this->encryptInto($work, $relative, $relative);
        } finally {
            @unlink($work);
        }
    }

    /**
     * Hands a chunk's plaintext to $read as an open stream.
     *
     * @param  callable(resource): void  $read
     */
    public function readPart(string $relative, callable $read): void
    {
        $plain = null;
        try {
            if ($this->isLocal()) {
                $path = $this->disk()->path($relative);
            } else {
                $plain = $path = self::tempPath('part');
                $this->decryptToFile($relative, $plain, $relative);
            }
            $part = fopen($path, 'rb');
            if ($part === false) {
                throw new RuntimeException('Missing chunk '.basename($relative).'.');
            }
            try {
                $read($part);
            } finally {
                fclose($part);
            }
        } finally {
            if ($plain !== null) {
                @unlink($plain);
            }
        }
    }

    /** The first bytes of a chunk's plaintext; '' when the chunk is not there. */
    public function partHead(string $relative, int $bytes): string
    {
        if ($this->isLocal()) {
            $path = $this->disk()->path($relative);

            return is_file($path) ? (string) file_get_contents($path, false, null, 0, $bytes) : '';
        }
        if (! $this->disk()->exists($relative)) {
            return '';
        }
        $head = '';
        $this->readPart($relative, function ($part) use (&$head, $bytes): void {
            $head = (string) stream_get_contents($part, $bytes);
        });

        return $head;
    }

    /**
     * The file names in a folder of parts, read at once: a bucket answers a list of 400 in one request and a question
     * about each of 400 in 400.
     *
     * @return list<string>
     */
    public function namesIn(string $directory): array
    {
        return array_map(basename(...), $this->disk()->files($directory));
    }

    /** Last time anything in a folder changed (on the server's own disk the folder itself too; a bucket has only files). */
    public function newestChange(string $directory): int
    {
        if ($this->isLocal()) {
            $root = $this->disk()->path($directory);
            $newest = (int) @filemtime($root);
            foreach (glob($root.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                $newest = max($newest, (int) @filemtime($file));
            }

            return $newest;
        }
        $newest = 0;
        foreach ($this->disk()->listContents($directory, true) as $item) {
            if ($item instanceof FileAttributes) {
                $newest = max($newest, (int) $item->lastModified());
            }
        }

        return $newest;
    }

    /**
     * How full the server disk that holds the files is. A bucket has no size to report: the fields are null, as the
     * API allows for any server that cannot read them.
     *
     * @return array{free: int|null, total: int|null}
     */
    public function diskSpace(): array
    {
        if (! $this->isLocal()) {
            return ['free' => null, 'total' => null];
        }
        $root = $this->disk()->path('');
        $free = is_dir($root) ? @disk_free_space($root) : false;
        $total = is_dir($root) ? @disk_total_space($root) : false;

        return ['free' => $free === false ? null : (int) $free, 'total' => $total === false ? null : (int) $total];
    }

    /**
     * Deletes files for good and never throws: a delete must not block a row's removal. What a bucket refuses after its
     * retries goes in `storage_deletions` for stt:reconcile, since no row is left to find the file by.
     *
     * @param  array<int, string|null>|string|null  $paths
     */
    public function erase(array|string|null $paths): void
    {
        foreach (array_filter((array) $paths) as $path) {
            try {
                $this->disk()->delete($path);
            } catch (Throwable $e) {
                report($e);
                if (! $this->isLocal()) {
                    $this->queueErasure($path, $e);
                }
            }
        }
    }

    /** stt:reconcile: tries the deletes that are due again, for as long as it takes. Returns how many went through. */
    public function retryErasures(): int
    {
        if ($this->isLocal()) {
            return 0;
        }
        $erased = 0;
        StorageDeletion::query()->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(self::RETRIES_PER_RUN)->get()
            ->each(function (StorageDeletion $deletion) use (&$erased): void {
                try {
                    $this->disk()->delete($deletion->path);
                    $deletion->delete();
                    $erased++;
                } catch (Throwable $e) {
                    $attempts = $deletion->attempts + 1;
                    $wait = min(self::MAX_RETRY_MINUTES, self::FIRST_RETRY_MINUTES * 2 ** min($attempts, 10));
                    $deletion->forceFill(['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 500), 'next_attempt_at' => now()->addMinutes($wait)])->save();
                }
            });

        return $erased;
    }

    private function queueErasure(string $path, Throwable $e): void
    {
        try {
            StorageDeletion::query()->updateOrCreate(['path' => $path], ['last_error' => mb_substr($e->getMessage(), 0, 500), 'next_attempt_at' => now()->addMinutes(self::FIRST_RETRY_MINUTES)]);
        } catch (Throwable $queued) {
            report($queued);
        }
    }

    private function upload(string $localPath, string $relative): void
    {
        $in = fopen($localPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open the file to store.');
        }
        try {
            $this->disk()->writeStream($relative, $in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    private function download(string $relative, string $localPath): void
    {
        $disk = $this->disk();
        $size = (int) $disk->size($relative);
        $in = $disk->readStream($relative);
        if (! is_resource($in)) {
            throw new RuntimeException('Cannot read '.$relative.' from the recordings disk.');
        }
        $out = fopen($localPath, 'wb');
        try {
            $copied = $out === false ? false : stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
        // A connection that dropped halfway looks like a short file: it must not pass for a damaged recording.
        if ($copied !== $size) {
            throw new RuntimeException('The download of '.$relative.' was cut short.');
        }
    }

    /** Resolved lazily so that listing and deleting work even before the key is configured. */
    private function cipher(): RecordingCipher
    {
        return app(RecordingCipher::class);
    }
}
