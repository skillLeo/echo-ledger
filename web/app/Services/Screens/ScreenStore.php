<?php

declare(strict_types=1);

namespace App\Services\Screens;

use App\Exceptions\ApiError;
use App\Jobs\DescribeScreenJob;
use App\Models\CaptureSession;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Services\ChunkedUploadService;
use App\Services\RecordingCipher;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Screen moments on disk (v1.5): each picture is kept as it was sent and as a 480 px wide WebP thumbnail, both encrypted
 * with the recordings key on the recordings disk (`screens/<year>/<month>/`, like the audio's `files/`). The screen's
 * id is bound into each file (the thumbnail's with "/thumb"), so files cannot be swapped between screens.
 */
class ScreenStore
{
    /** A picture is at most 3 MB. */
    public const MAX_BYTES = 3 * 1024 * 1024;

    /** A recording keeps at most this many screens (`409 too_many_screens`). */
    public const MAX_PER_RECORDING = 400;

    public const THUMB_WIDTH = 480;

    /** Larger pictures are refused before they are decoded (a small file can hold a huge picture). */
    public const MAX_PIXELS = 25_000_000;

    public const MAX_SIDE = 16384;

    public function __construct(private readonly ChunkedUploadService $uploads) {}

    /**
     * What the file is, read from its bytes (never from its name or the type the client sent): WebP, PNG or JPEG
     * whose header and decoded size agree, with its size in pixels; null for anything else.
     *
     * @return array{mime: string, width: int, height: int}|null
     */
    public static function sniff(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 16);
        fclose($handle);
        $mime = match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        $info = $mime !== null ? @getimagesize($path) : false;
        $type = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
        if ($info === false || ($type[$info[2]] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return ['mime' => $mime, 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * Keeps a picture of the recording: the same picture (by SHA-256) twice is the screen already kept, not a copy.
     * `409 too_many_screens` at the cap, and since v1.5 `403 storage_limit_reached` at the plan's storage. The text and
     * description are made afterwards on the queue.
     *
     * @param  array{mime: string, width: int, height: int}  $picture  what sniff() found
     * @param  array{at_ms: int, width: int, height: int, trigger: string, sha256: string}  $meta
     * @return array{0: Screen, 1: bool} the screen, and whether it was kept now
     */
    public function add(CaptureSession $session, string $plainPath, array $picture, array $meta): array
    {
        $sha = strtolower($meta['sha256']);
        $existing = $this->twin($session, $sha);
        if ($existing instanceof Screen) {
            return [$existing, false];
        }
        $this->assertRoom($session);
        app(PlanLimits::class)->assertStorage($session->workspace_id, (int) filesize($plainPath));

        $id = (string) Str::ulid();
        $folder = 'screens/'.now()->format('Y/m');
        $imagePath = $folder.'/'.$id.'.elr';
        $thumbPath = $folder.'/'.$id.'-thumb.elr';
        $disk = Storage::disk('recordings');
        $disk->makeDirectory($folder);
        $thumbPlain = $this->uploads->tempPath('screen-thumb');
        $written = [];
        try {
            self::writeThumbnail($plainPath, $thumbPlain);
            $this->cipher()->encryptFile($plainPath, $disk->path($imagePath), $id);
            $written[] = $imagePath;
            $this->cipher()->encryptFile($thumbPlain, $disk->path($thumbPath), self::thumbKey($id));
            $written[] = $thumbPath;
            $result = DB::transaction(function () use ($session, $sha, $id, $picture, $meta, $imagePath, $thumbPath, $plainPath): array {
                // One picture of a recording at a time: two sent together can neither pass the cap nor both be kept.
                CaptureSession::query()->withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->value('id');
                $twin = $this->twin($session, $sha);
                if ($twin instanceof Screen) {
                    return [$twin, false];
                }
                $this->assertRoom($session);
                $screen = new Screen([
                    'workspace_id' => $session->workspace_id,
                    'capture_session_id' => $session->id,
                    'at_ms' => $meta['at_ms'],
                    'width' => $meta['width'],
                    'height' => $meta['height'],
                    'trigger' => $meta['trigger'],
                    'sha256' => $sha,
                    'mime' => $picture['mime'],
                    'image_path' => $imagePath,
                    'thumb_path' => $thumbPath,
                    'bytes' => (int) filesize($plainPath),
                    // Since v1.5 the plan's storage counts what the two encrypted files take.
                    'stored_bytes' => self::sizeOf($imagePath) + self::sizeOf($thumbPath),
                ]);
                $screen->id = $id;
                $screen->save();

                return [$screen, true];
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same picture arrived twice at the same moment: the other request kept it.
            $disk->delete($written);
            $twin = $this->twin($session, $sha);

            return $twin instanceof Screen ? [$twin, false] : throw $e;
        } catch (Throwable $e) {
            $disk->delete($written);
            throw $e;
        } finally {
            @unlink($thumbPlain);
        }

        if (! $result[1]) {
            $disk->delete($written);

            return $result;
        }
        // The library item's screen_count changed: the other devices pick it up on their next sync.
        $session->touch();
        if (app(ScreenDescriptions::class)->enabled()) {
            DescribeScreenJob::dispatch($id);
        }

        return $result;
    }

    /**
     * The screens of a recording in time order ("Screen 1" first). Reached through the recording, so also for a
     * recording a teammate sent (its workspace is the sender's).
     *
     * @return Builder<Screen>
     */
    public static function ordered(string $sessionId): Builder
    {
        return Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $sessionId)->orderBy('at_ms')->orderBy('id');
    }

    /** The screen's number in its recording's time order, from 1. */
    public static function indexOf(Screen $screen): int
    {
        return 1 + Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $screen->capture_session_id)
            ->where(fn (Builder $q) => $q->where('at_ms', '<', $screen->at_ms)
                ->orWhere(fn (Builder $same) => $same->where('at_ms', $screen->at_ms)->where('id', '<', $screen->id)))
            ->count();
    }

    /** The decrypted picture (or its thumbnail), or null when its file is gone. At most 3 MB, so read into memory. */
    public function picture(Screen $screen, bool $thumb = false): ?string
    {
        $path = $thumb ? $screen->thumb_path : $screen->image_path;
        $disk = Storage::disk('recordings');
        if ($path === null || ! $disk->exists($path)) {
            return null;
        }
        $plain = $this->cipher()->decryptToMemory($disk->path($path), $thumb ? self::thumbKey($screen->id) : $screen->id);
        $bytes = '';
        while (! $plain->eof()) {
            $bytes .= $plain->fread(1 << 20);
        }

        return $bytes;
    }

    /** Decrypts the picture into [$dstPath] without holding it in memory (the account export); its size, or null when it is gone. */
    public function pictureToFile(Screen $screen, string $dstPath): ?int
    {
        $disk = Storage::disk('recordings');
        if ($screen->image_path === null || ! $disk->exists($screen->image_path)) {
            return null;
        }

        return $this->cipher()->decryptToFile($disk->path($screen->image_path), $dstPath, $screen->id);
    }

    /** Deletes one screen and its two files. */
    public function delete(Screen $screen): void
    {
        $this->deleteFiles([$screen->image_path, $screen->thumb_path]);
        Screen::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($screen->id)->delete();
    }

    /** Erases every screen of a recording, files first (the undo bin's end, retention, an erase now). */
    public function eraseFor(CaptureSession $session): void
    {
        $screens = Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $session->id)->get(['id', 'image_path', 'thumb_path']);
        if ($screens->isEmpty()) {
            return;
        }
        $this->deleteFiles($screens->flatMap(fn (Screen $s): array => [$s->image_path, $s->thumb_path])->all());
        Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $session->id)->delete();
    }

    /**
     * A copy of the screen for another recording ("Save to my library"): both files decrypted and encrypted again
     * under the copy's id, with the text and description. Returns the files it wrote; when it fails it erases them
     * itself. A screen not described yet is described for the copy too, once the save is kept.
     *
     * @return list<string>
     */
    public function copy(Screen $original, CaptureSession $copy): array
    {
        $id = (string) Str::ulid();
        $folder = 'screens/'.now()->format('Y/m');
        $disk = Storage::disk('recordings');
        $disk->makeDirectory($folder);
        $written = [];
        try {
            foreach ([false, true] as $thumb) {
                $source = $thumb ? $original->thumb_path : $original->image_path;
                if ($source === null || ! $disk->exists($source)) {
                    continue;
                }
                $plain = $this->uploads->tempPath('screen-copy');
                try {
                    $this->cipher()->decryptToFile($disk->path($source), $plain, $thumb ? self::thumbKey($original->id) : $original->id);
                    $target = $folder.'/'.$id.($thumb ? '-thumb' : '').'.elr';
                    // Counted before it is written, so a file a failure leaves half written is erased too.
                    $written[$thumb ? 'thumb' : 'image'] = $target;
                    $this->cipher()->encryptFile($plain, $disk->path($target), $thumb ? self::thumbKey($id) : $id);
                } finally {
                    @unlink($plain);
                }
            }
            if (! isset($written['image'])) {
                $disk->delete(array_values($written));

                return [];
            }
            $screen = new Screen([
                'workspace_id' => $copy->workspace_id,
                'capture_session_id' => $copy->id,
                'image_path' => $written['image'],
                'thumb_path' => $written['thumb'] ?? null,
                'stored_bytes' => self::sizeOf($written['image']) + self::sizeOf($written['thumb'] ?? null),
                ...$original->only(['at_ms', 'width', 'height', 'trigger', 'sha256', 'mime', 'bytes', 'ocr_text', 'description', 'search_text', 'described_at']),
            ]);
            $screen->id = $id;
            $screen->save();
        } catch (Throwable $e) {
            // The caller only learns of the files returned to it: these are erased here.
            $disk->delete(array_values($written));
            throw $e;
        }
        // Its description may still be waiting on the queue: the copy gets its own job, which takes the original's
        // answer when that has come by then.
        if ($original->described_at === null && app(ScreenDescriptions::class)->enabled()) {
            DescribeScreenJob::dispatch($id, $original->id)->afterCommit();
        }

        return array_values($written);
    }

    /** The additional data the thumbnail's file is encrypted with. */
    public static function thumbKey(string $id): string
    {
        return $id.'/thumb';
    }

    /** The 480 px wide WebP thumbnail (narrower pictures keep their width), made with GD. */
    public static function writeThumbnail(string $plainPath, string $targetPath): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($plainPath));
        if ($image === false) {
            throw new UnreadablePicture('The picture could not be read.');
        }
        $width = min(self::THUMB_WIDTH, imagesx($image));
        $height = max(1, (int) round(imagesy($image) * $width / max(1, imagesx($image))));
        // Resampled by hand: imagescale() refuses its bicubic modes on some GD builds.
        $thumb = imagecreatetruecolor($width, $height);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        unset($image);
        if (! imagewebp($thumb, $targetPath, 80)) {
            throw new UnreadablePicture('The thumbnail could not be written.');
        }
    }

    private function twin(CaptureSession $session, string $sha): ?Screen
    {
        $screen = Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $session->id)->where('sha256', $sha)->first();

        return $screen instanceof Screen ? $screen : null;
    }

    private function assertRoom(CaptureSession $session): void
    {
        if (Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $session->id)->count() >= self::MAX_PER_RECORDING) {
            throw new ApiError(409, 'too_many_screens', 'This recording already keeps '.self::MAX_PER_RECORDING.' screens.');
        }
    }

    /** @param  list<string|null>  $paths */
    private function deleteFiles(array $paths): void
    {
        try {
            Storage::disk('recordings')->delete(array_values(array_filter($paths)));
        } catch (Throwable $e) {
            report($e); // a missing file must never block an erase
        }
    }

    private function cipher(): RecordingCipher
    {
        return app(RecordingCipher::class);
    }

    /** Bytes a file takes on the recordings disk, 0 when it is not there. */
    private static function sizeOf(?string $path): int
    {
        if ($path === null) {
            return 0;
        }
        $full = Storage::disk('recordings')->path($path);

        return is_file($full) ? (int) filesize($full) : 0;
    }
}
