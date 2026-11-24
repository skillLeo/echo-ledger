<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Screens\ScreenStore;
use App\Services\Storage\RecordingFiles;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Profile pictures (since 2.0.0-l, docs/API.md "Account and security from the apps"). A JPEG, PNG or WebP (decided from
 * the bytes) of at most 5 MB and 25 MP, at most 4 to 1, is decoded, turned upright, cropped to a centred square and
 * saved as WebP at 512 and 128 px: re-encoding drops EXIF, GPS and every other piece of metadata. The copies are
 * encrypted on the recordings disk like screens and receipts, named by `users.avatar_version`, which changes with every
 * picture and never repeats (a timestamp), so the apps can cache a picture by person and version. Picture bytes never
 * go into a log or the audit log.
 */
final class Avatars
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MIN_SIDE = 128;

    public const MAX_RATIO = 4.0;

    /** The sizes kept, in pixels; the first is the default of the URL. */
    public const SIZES = [128, 512];

    /** Days a signed `avatar_url` works. */
    public const URL_DAYS = 7;

    public function __construct(private readonly RecordingFiles $files, private readonly AuditLog $audit) {}

    /**
     * `avatar_url` and `avatar_version` beside a person the API shows (null and null = initials), and since 2.0.0-r
     * (BE-06) `avatar_color`, the initials' circle (0–7; null only when there is no person).
     *
     * @return array{avatar_url: string|null, avatar_version: int|null, avatar_color: int|null}
     */
    public static function of(?User $user): array
    {
        $version = $user?->avatar_version;
        $color = $user === null ? null : (int) $user->avatar_color;
        if ($user === null || $version === null) {
            return ['avatar_url' => null, 'avatar_version' => null, 'avatar_color' => $color];
        }

        return ['avatar_url' => self::url($user->id, $version), 'avatar_version' => $version, 'avatar_color' => $color];
    }

    /**
     * The signed URL of a version, valid for 7 days from the start of this hour, so the same version read again in the
     * hour has the same URL. `?size=512` may be added: the signature leaves `size` out.
     */
    public static function url(int $userId, int $version): string
    {
        return URL::temporarySignedRoute('api.avatars.show', now()->startOfHour()->addDays(self::URL_DAYS), ['user' => $userId, 'version' => $version]);
    }

    /** Keeps [$file] as the person's picture; returns the new version. */
    public function store(User $user, UploadedFile $file): int
    {
        $path = (string) $file->getRealPath();
        $bytes = (int) $file->getSize();
        if ($bytes > self::MAX_BYTES) {
            throw new ApiError(413, 'too_large', 'The picture is larger than 5 MB.', ['max_bytes' => self::MAX_BYTES]);
        }
        $picture = $this->check($path);
        $temp = [];
        foreach (self::SIZES as $size) {
            $temp[$size] = RecordingFiles::tempPath('avatar-'.$size);
        }
        try {
            ScreenStore::decoding(fn () => self::render($path, $picture, $temp), $user->id);

            return $this->swap($user, $temp);
        } finally {
            foreach ($temp as $plain) {
                @unlink($plain);
            }
        }
    }

    /** Removes the person's picture and its stored copies; false when there was none. */
    public function remove(User $user): bool
    {
        $old = DB::transaction(function () use ($user): ?int {
            $row = User::query()->whereKey($user->id)->lockForUpdate()->first(['id', 'avatar_version']);
            $old = $row?->avatar_version;
            if ($old !== null) {
                User::query()->whereKey($user->id)->update(['avatar_version' => null]);
            }

            return $old;
        });
        $user->avatar_version = null;
        $user->syncOriginalAttribute('avatar_version');
        if ($old === null) {
            return false;
        }
        // The next picture's version stays above this one even within the same second (versions are never reused).
        Cache::forever(self::floorKey($user->id), $old);
        $this->files->erase(self::paths($user->id, $old));
        $this->audit->record('avatar_removed', $user->id, $user);

        return true;
    }

    /** Erasing the account (AccountEraser): the stored copies of its picture go too. */
    public function eraseFilesOf(User $user): void
    {
        if ($user->avatar_version !== null) {
            $this->files->erase(self::paths($user->id, $user->avatar_version));
        }
    }

    /**
     * The WebP bytes of one stored copy, or null when that version is not the person's picture (replaced or removed).
     */
    public function read(int $userId, int $version, int $size): ?string
    {
        if (! in_array($size, self::SIZES, true) || User::query()->whereKey($userId)->value('avatar_version') !== $version) {
            return null;
        }
        $path = self::path($userId, $version, $size);
        if (! $this->files->exists($path)) {
            return null;
        }
        $plain = $this->files->decryptToMemory($path, self::aad($userId, $version, $size));
        $bytes = '';
        while (! $plain->eof()) {
            $bytes .= $plain->fread(1 << 20);
        }

        return $bytes;
    }

    /** @return list<string> */
    public static function paths(int $userId, int $version): array
    {
        return array_map(fn (int $size): string => self::path($userId, $version, $size), self::SIZES);
    }

    public static function path(int $userId, int $version, int $size): string
    {
        return "avatars/{$userId}/{$version}-{$size}.elr";
    }

    /**
     * What the picture is, from its bytes; `422 invalid_image` with the reason otherwise.
     *
     * @return array{mime: string, width: int, height: int}
     */
    private function check(string $path): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        $known = str_starts_with($head, "\x89PNG\r\n\x1a\n") || str_starts_with($head, "\xFF\xD8\xFF")
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP');
        if (! $known) {
            throw self::invalid('format', 'Use a JPEG, PNG or WebP picture.');
        }
        $picture = ScreenStore::sniff($path);
        if ($picture === null) {
            throw self::invalid('broken', 'The picture could not be read. Try another one.');
        }
        ['width' => $w, 'height' => $h] = $picture;
        if ($w * $h > ScreenStore::MAX_PIXELS || max($w, $h) > ScreenStore::MAX_SIDE) {
            throw self::invalid('too_many_pixels', 'The picture is too large. Use one of at most 25 megapixels.');
        }
        if (min($w, $h) < self::MIN_SIDE) {
            throw self::invalid('too_small', 'The picture is too small. Use one of at least 128 by 128 pixels.');
        }
        if (max($w, $h) / min($w, $h) > self::MAX_RATIO) {
            throw self::invalid('ratio', 'The picture is too long and narrow. Use one closer to a square.');
        }

        return $picture;
    }

    /**
     * Decodes, turns upright, crops to the centred square and writes each size as WebP into [$targets].
     *
     * @param  array{mime: string, width: int, height: int}  $picture
     * @param  array<int, string>  $targets  size => path
     */
    private static function render(string $path, array $picture, array $targets): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image instanceof GdImage) {
            throw self::invalid('broken', 'The picture could not be read. Try another one.');
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $image = PaymentProofs::upright($image, $picture['mime'] === 'image/jpeg' ? PaymentProofs::jpegOrientation($path) : 1);
        [$width, $height] = [imagesx($image), imagesy($image)];
        $side = min($width, $height);
        $x = intdiv($width - $side, 2);
        $y = intdiv($height - $side, 2);
        foreach ($targets as $size => $target) {
            $square = imagecreatetruecolor($size, $size);
            imagealphablending($square, false);
            imagesavealpha($square, true);
            imagecopyresampled($square, $image, 0, 0, $x, $y, $size, $size, $side, $side);
            if (! imagewebp($square, $target, 85)) {
                throw self::invalid('broken', 'The picture could not be read. Try another one.');
            }
        }
    }

    /**
     * Stores the copies under a new version, makes it the person's and erases the old copies. The person's row stays
     * locked meanwhile (two small files), so two pictures sent at once each get their own version.
     *
     * @param  array<int, string>  $plain  size => path
     */
    private function swap(User $user, array $plain): int
    {
        $written = [];
        try {
            [$old, $version] = DB::transaction(function () use ($user, $plain, &$written): array {
                $old = User::query()->whereKey($user->id)->lockForUpdate()->value('avatar_version');
                $old = $old === null ? null : (int) $old;
                $floor = (int) max($old ?? 0, (int) $user->avatar_version, (int) Cache::get(self::floorKey($user->id), 0));
                $version = max((int) now()->timestamp, $floor + 1);
                foreach ($plain as $size => $path) {
                    $relative = self::path($user->id, $version, $size);
                    $this->files->encryptInto($path, $relative, self::aad($user->id, $version, $size));
                    $written[] = $relative;
                }
                User::query()->whereKey($user->id)->update(['avatar_version' => $version]);

                return [$old, $version];
            });
        } catch (Throwable $e) {
            $this->files->erase($written);
            throw $e;
        }
        if ($old !== null) {
            $this->files->erase(self::paths($user->id, $old));
        }
        $user->avatar_version = $version;
        $user->syncOriginalAttribute('avatar_version');
        $this->audit->record('avatar_changed', $user->id, $user);

        return $version;
    }

    private static function floorKey(int $userId): string
    {
        return 'avatar-version-floor:'.$userId;
    }

    private static function aad(int $userId, int $version, int $size): string
    {
        return "avatar/{$userId}/{$version}/{$size}";
    }

    private static function invalid(string $reason, string $message): ApiError
    {
        return new ApiError(422, 'invalid_image', $message, ['reason' => $reason]);
    }
}
