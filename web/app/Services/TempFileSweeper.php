<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UploadStatus;
use App\Models\Recording;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes plaintext audio that a crashed request or worker left behind: decrypted and assembly copies in the temp
 * directory, half-written chunk files, and the upload parts of recordings that are gone or failed.
 * Runs as the last step of `stt:reconcile`.
 */
final class TempFileSweeper
{
    /** Upload parts of a deleted or failed recording are kept this long before they are removed. */
    public const ORPHANED_PARTS_HOURS = 24;

    /** @return int files and part directories removed */
    public function sweep(int $olderThanMinutes = 60): int
    {
        $cutoff = now()->subMinutes(max(1, $olderThanMinutes))->getTimestamp();

        return $this->sweepTempDirectory($cutoff) + $this->sweepParts($cutoff, now()->subHours(self::ORPHANED_PARTS_HOURS)->getTimestamp());
    }

    private function sweepTempDirectory(int $cutoff): int
    {
        $dir = (string) config('echoledger.tmp_dir');
        if ($dir === '' || ! is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.DIRECTORY_SEPARATOR.$name;
            // Dotfiles (.gitignore) belong to the deployment, not to a request.
            if (str_starts_with($name, '.') || ! is_file($path)) {
                continue;
            }
            $removed += $this->removeIfOlder($path, $cutoff);
        }

        return $removed;
    }

    private function sweepParts(int $tmpCutoff, int $partsCutoff): int
    {
        $disk = Storage::disk('recordings');
        if (! $disk->directoryExists('parts')) {
            return 0;
        }

        $removed = 0;
        foreach ($disk->directories('parts') as $relative) {
            $id = basename($relative);
            $root = $disk->path($relative);
            $recording = Recording::query()->find($id, ['id', 'upload_status']);
            $orphaned = $recording === null || $recording->upload_status === UploadStatus::Failed;

            if ($orphaned && $this->newestChange($root) < $partsCutoff) {
                $disk->deleteDirectory($relative);
                $removed++;

                continue;
            }
            // A chunk upload that died before its rename leaves "<index>.part.<random>.tmp" behind.
            foreach (glob($root.DIRECTORY_SEPARATOR.'*.tmp') ?: [] as $tmp) {
                $removed += $this->removeIfOlder($tmp, $tmpCutoff);
            }
        }

        return $removed;
    }

    /** Last time anything in the directory changed (the directory itself or any file in it). */
    private function newestChange(string $dir): int
    {
        $newest = (int) @filemtime($dir);
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            $newest = max($newest, (int) @filemtime($file));
        }

        return $newest;
    }

    private function removeIfOlder(string $path, int $cutoff): int
    {
        $modified = @filemtime($path);
        if ($modified === false || $modified >= $cutoff) {
            return 0;
        }
        try {
            return unlink($path) ? 1 : 0;
        } catch (Throwable $e) {
            report($e); // locked or already gone: the next run tries again

            return 0;
        }
    }
}
