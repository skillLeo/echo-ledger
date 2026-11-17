<?php

declare(strict_types=1);

namespace App\Services\Screens;

use App\Exceptions\ApiError;
use App\Jobs\DescribeScreenJob;
use App\Models\CaptureSession;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Screen;
use App\Services\ChunkedUploadService;
use App\Services\Storage\RecordingFiles;
use App\Services\Workspaces\PlanLimits;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
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

    /** Since v1.8 P4.1, 320 px (480 before: those thumbnails stay as they are). */
    public const THUMB_WIDTH = 320;

    /** Since v1.8 P4.1: at most this many screens in any hour of a recording, on top of MAX_PER_RECORDING. */
    public const MAX_PER_HOUR = 120;

    /** A near-duplicate is looked for among the screens this close in time (ScreenSimilarity). */
    public const NEIGHBOUR_MS = 300_000;

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
        $this->assertRoom($session, (int) $meta['at_ms']);
        app(PlanLimits::class)->assertStorage($session->workspace_id, (int) filesize($plainPath), $session->user_id);

        $id = (string) Str::ulid();
        $folder = 'screens/'.now()->format('Y/m');
        $imagePath = $folder.'/'.$id.'.elr';
        $thumbPath = $folder.'/'.$id.'-thumb.elr';
        $files = $this->files();
        $thumbPlain = $this->uploads->tempPath('screen-thumb');
        $written = [];
        try {
            // The decodes (the thumbnail, the comparison) in a decode slot, with room for them.
            $near = self::decoding(function () use ($plainPath, $thumbPlain, $session, $meta): ?Screen {
                self::writeThumbnail($plainPath, $thumbPlain);

                // Since v1.8 P4.1: the same screen sent again a moment later (re-compressed, so another sha256) is
                // the one kept already, as an exact repeat is (ScreenSimilarity, conservative).
                return $this->nearDuplicate($session, $plainPath, $meta);
            });
            if ($near instanceof Screen) {
                return [$near, false];
            }
            $storedBytes = $files->encryptInto($plainPath, $imagePath, $id);
            $written[] = $imagePath;
            $storedBytes += $files->encryptInto($thumbPlain, $thumbPath, self::thumbKey($id));
            $written[] = $thumbPath;
            $result = DB::transaction(function () use ($session, $sha, $id, $picture, $meta, $imagePath, $thumbPath, $plainPath, $storedBytes): array {
                // One picture of a recording at a time: two sent together can neither pass the cap nor both be kept.
                CaptureSession::query()->withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->value('id');
                $twin = $this->twin($session, $sha);
                if ($twin instanceof Screen) {
                    return [$twin, false];
                }
                $this->assertRoom($session, (int) $meta['at_ms']);
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
                    'stored_bytes' => $storedBytes,
                ]);
                $screen->id = $id;
                $screen->save();

                return [$screen, true];
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same picture arrived twice at the same moment: the other request kept it.
            $files->erase($written);
            $twin = $this->twin($session, $sha);

            return $twin instanceof Screen ? [$twin, false] : throw $e;
        } catch (Throwable $e) {
            $files->erase($written);
            throw $e;
        } finally {
            @unlink($thumbPlain);
        }

        if (! $result[1]) {
            $files->erase($written);

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
        $files = $this->files();
        if ($path === null || ! $files->disk()->exists($path)) {
            return null;
        }
        $plain = $files->decryptToMemory($path, $thumb ? self::thumbKey($screen->id) : $screen->id);
        $bytes = '';
        while (! $plain->eof()) {
            $bytes .= $plain->fread(1 << 20);
        }

        return $bytes;
    }

    /** Decrypts the picture into [$dstPath] without holding it in memory (the account export); its size, or null when it is gone. */
    public function pictureToFile(Screen $screen, string $dstPath): ?int
    {
        $files = $this->files();
        if ($screen->image_path === null || ! $files->disk()->exists($screen->image_path)) {
            return null;
        }

        return $files->decryptToFile($screen->image_path, $dstPath, $screen->id);
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
        $files = $this->files();
        $written = [];
        $storedBytes = 0;
        try {
            foreach ([false, true] as $thumb) {
                $source = $thumb ? $original->thumb_path : $original->image_path;
                if ($source === null || ! $files->disk()->exists($source)) {
                    continue;
                }
                $plain = $this->uploads->tempPath('screen-copy');
                try {
                    $files->decryptToFile($source, $plain, $thumb ? self::thumbKey($original->id) : $original->id);
                    $target = $folder.'/'.$id.($thumb ? '-thumb' : '').'.elr';
                    // Counted before it is written, so a file a failure leaves half written is erased too.
                    $written[$thumb ? 'thumb' : 'image'] = $target;
                    $storedBytes += $files->encryptInto($plain, $target, $thumb ? self::thumbKey($id) : $id);
                } finally {
                    @unlink($plain);
                }
            }
            if (! isset($written['image'])) {
                $files->erase(array_values($written));

                return [];
            }
            $screen = new Screen([
                'workspace_id' => $copy->workspace_id,
                'capture_session_id' => $copy->id,
                'image_path' => $written['image'],
                'thumb_path' => $written['thumb'] ?? null,
                'stored_bytes' => $storedBytes,
                ...$original->only(['at_ms', 'width', 'height', 'trigger', 'sha256', 'mime', 'bytes', 'ocr_text', 'description', 'search_text', 'described_at']),
            ]);
            $screen->id = $id;
            $screen->save();
        } catch (Throwable $e) {
            // The caller only learns of the files returned to it: these are erased here.
            $files->erase(array_values($written));
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

    /** The [$width] (a screen's: 320 px) wide WebP thumbnail (narrower pictures keep their width), made with GD. */
    public static function writeThumbnail(string $plainPath, string $targetPath, int $width = self::THUMB_WIDTH): void
    {
        $maxWidth = $width;
        $image = @imagecreatefromstring((string) file_get_contents($plainPath));
        if ($image === false) {
            throw new UnreadablePicture('The picture could not be read.');
        }
        $width = min($maxWidth, imagesx($image));
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

    /** Memory for decoding the largest picture taken (25 MP at 4 bytes a pixel, with room to spare). */
    public const DECODE_MEMORY = 512 * 1024 * 1024;

    /**
     * Runs [$work], which decodes pictures, in one of the server's decode slots (echoledger.picture_decodes: 2 by
     * default, each a cache lock, so the bound holds for every PHP process sharing the cache; a picture takes up to
     * about 200 MB), with PHP's memory limit raised to DECODE_MEMORY where it is lower. The limit
     * is put back afterwards, so a queue worker or Octane keeps its own; when the process still holds more than its old
     * limit (rare: GD frees its pictures), the raised one stays for the rest of the process rather than fail. Throws
     * `503 server_busy` (Retry-After 5) when no slot frees up in time (20 seconds by default).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function decoding(Closure $work): mixed
    {
        $slot = self::decodeSlot();
        $previous = self::roomToDecode();
        try {
            return $work();
        } finally {
            if ($previous !== null && memory_get_usage(true) < self::bytes($previous)) {
                @ini_set('memory_limit', $previous);
            }
            $slot->release();
        }
    }

    /** A free decode slot, waiting for one as long as the configuration says. */
    private static function decodeSlot(): Lock
    {
        $slots = max(1, (int) config('echoledger.picture_decodes.slots', 2));
        $deadline = microtime(true) + (float) config('echoledger.picture_decodes.wait_seconds', 20);
        do {
            for ($i = 0; $i < $slots; $i++) {
                // Held for at most a minute even if the process dies mid-decode.
                $lock = Cache::lock('pictures:decode:'.$i, 60);
                if ($lock->get()) {
                    return $lock;
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(200_000);
        } while (true);

        throw new ApiError(503, 'server_busy', 'The server is busy with other pictures. Try again in a moment.', [], ['Retry-After' => '5']);
    }

    /**
     * Raises PHP's memory limit to DECODE_MEMORY where it is lower (a 128M default would fail on a 25 MP picture).
     * Use decoding(), which also puts it back.
     *
     * @return string|null the limit before, when it was raised
     */
    public static function roomToDecode(): ?string
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1' || self::bytes($limit) >= self::DECODE_MEMORY) {
            return null;
        }
        ini_set('memory_limit', (string) self::DECODE_MEMORY);

        return $limit;
    }

    /** A php.ini size ("128M", "262144K", "2G", "536870912") in bytes. */
    private static function bytes(string $size): int
    {
        return (int) $size * match (strtolower(substr($size, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }

    private function twin(CaptureSession $session, string $sha): ?Screen
    {
        $screen = Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $session->id)->where('sha256', $sha)->first();

        return $screen instanceof Screen ? $screen : null;
    }

    private function assertRoom(CaptureSession $session, int $atMs): void
    {
        $screens = fn () => Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $session->id);
        if ($screens()->count() >= self::MAX_PER_RECORDING) {
            throw new ApiError(409, 'too_many_screens', 'This recording already keeps '.self::MAX_PER_RECORDING.' screens.');
        }
        // Since v1.8 P4.1: at most 120 in the hour of the recording up to this moment.
        if ($screens()->where('at_ms', '>', $atMs - 3_600_000)->where('at_ms', '<=', $atMs)->count() >= self::MAX_PER_HOUR) {
            throw new ApiError(409, 'too_many_screens', 'This recording already keeps '.self::MAX_PER_HOUR.' screens in an hour.');
        }
    }

    /**
     * The screen of [$session] just before or after [$meta]'s moment (within 5 minutes, the same size) that shows the
     * same picture as the one at [$plainPath], if one does (the pictures themselves, ScreenSimilarity).
     *
     * @param  array{at_ms: int, width: int, height: int}  $meta
     */
    private function nearDuplicate(CaptureSession $session, string $plainPath, array $meta): ?Screen
    {
        $base = fn () => Screen::query()->withoutGlobalScope(WorkspaceScope::class)->where('capture_session_id', $session->id)
            ->where('width', $meta['width'])->where('height', $meta['height']);
        $neighbours = array_filter([
            $base()->where('at_ms', '<=', $meta['at_ms'])->where('at_ms', '>=', $meta['at_ms'] - self::NEIGHBOUR_MS)->orderByDesc('at_ms')->first(),
            $base()->where('at_ms', '>', $meta['at_ms'])->where('at_ms', '<=', $meta['at_ms'] + self::NEIGHBOUR_MS)->orderBy('at_ms')->first(),
        ]);
        if ($neighbours === []) {
            return null;
        }
        // The size it claims, checked against the picture; one out of bounds is kept, never compared.
        $mine = ScreenSimilarity::signature((string) file_get_contents($plainPath), (int) $meta['width'], (int) $meta['height']);
        if ($mine === null) {
            return null;
        }
        // One neighbour decoded at a time, each freed before the next.
        foreach ($neighbours as $neighbour) {
            $picture = $this->picture($neighbour);
            $theirs = $picture !== null ? ScreenSimilarity::signature($picture, $neighbour->width, $neighbour->height) : null;
            unset($picture);
            if ($theirs !== null && ScreenSimilarity::same($mine, $theirs)) {
                return $neighbour;
            }
            unset($theirs);
        }

        return null;
    }

    /** @param  list<string|null>  $paths */
    private function deleteFiles(array $paths): void
    {
        $this->files()->erase(array_values(array_filter($paths))); // a missing file must never block an erase
    }

    private function files(): RecordingFiles
    {
        return app(RecordingFiles::class);
    }
}
