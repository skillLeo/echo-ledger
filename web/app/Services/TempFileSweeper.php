<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UploadStatus;
use App\Models\Recording;
use App\Models\RequestLink;
use App\Models\RequestUpload;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Sharing\RequestLinks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes plaintext audio that a crashed request or worker left behind: decrypted and assembly copies in the temp
 * directory, half-written chunk files, and the upload parts of recordings that are gone or failed. Since v1.5 it also
 * ends uploads through request links that nobody finished, with what they left behind.
 * Runs as the last step of `stt:reconcile`.
 */
final class TempFileSweeper
{
    /** Upload parts of a deleted or failed recording are kept this long before they are removed. */
    public const ORPHANED_PARTS_HOURS = 24;

    /** How many abandoned request-link uploads one run ends; the next run takes the rest. */
    private const REQUEST_UPLOADS_PER_RUN = 200;

    /** @return int files and part directories removed, and request-link uploads ended */
    public function sweep(int $olderThanMinutes = 60): int
    {
        $cutoffAt = now()->subMinutes(max(1, $olderThanMinutes));
        $cutoff = $cutoffAt->getTimestamp();

        return $this->sweepTempDirectory($cutoff) + $this->sweepParts($cutoff, now()->subHours(self::ORPHANED_PARTS_HOURS)->getTimestamp())
            + $this->sweepRequestUploads($cutoffAt);
    }

    /**
     * Request links (v1.5): an upload is ended once it has received nothing for RequestLinks::ABANDONED_HOURS, or an
     * hour after its link was revoked or expired (nobody can resume it then). Then the document parts of uploads that
     * no longer run are removed (a refused or finished upload removes its own; this catches a request that died).
     */
    private function sweepRequestUploads(Carbon $cutoff): int
    {
        $ended = RequestLink::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where(fn ($q) => $q->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now()))->select('id');
        $abandoned = RequestUpload::query()->where('status', 'uploading')
            ->where(fn ($q) => $q->where('updated_at', '<', now()->subHours(RequestLinks::ABANDONED_HOURS))
                ->orWhere(fn ($q) => $q->where('updated_at', '<', $cutoff)->whereIn('request_link_id', $ended)))
            ->orderBy('updated_at')->limit(self::REQUEST_UPLOADS_PER_RUN)->get();

        $removed = 0;
        foreach ($abandoned as $upload) {
            try {
                app(RequestLinks::class)->abandon($upload);
                $removed++;
            } catch (Throwable $e) {
                report($e); // the next run tries again
            }
        }

        $disk = Storage::disk('recordings');
        if (! $disk->directoryExists('request-parts')) {
            return $removed;
        }
        foreach ($disk->directories('request-parts') as $relative) {
            $upload = RequestUpload::query()->find(basename($relative), ['id', 'status']);
            if (($upload === null || $upload->status !== 'uploading') && $this->newestChange($disk->path($relative)) < $cutoff->getTimestamp()) {
                $disk->deleteDirectory($relative);
                $removed++;
            }
        }

        return $removed;
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
