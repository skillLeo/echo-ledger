<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One build of an app on the downloads page (since v1.5): the phone's website build, Windows, or a Mac build.
 *
 * @property string $id
 * @property string $platform
 * @property string $version
 * @property int|null $code
 * @property string $path
 * @property string $file_name
 * @property int $bytes
 * @property string $sha256
 * @property string|null $updater_path
 * @property string|null $updater_file_name
 * @property string|null $signature
 * @property string|null $notes
 * @property Carbon $published_at
 */
final class AppRelease extends Model
{
    use HasUlids;

    public const ANDROID = 'android';

    public const WINDOWS = 'windows';

    public const MAC_ARM = 'macos-arm';

    public const MAC_INTEL = 'macos-intel';

    public const PLATFORMS = [self::ANDROID, self::WINDOWS, self::MAC_ARM, self::MAC_INTEL];

    /** The desktop updater's names for the desktop platforms (Tauri's static manifest keys). */
    public const UPDATER_KEYS = [self::WINDOWS => 'windows-x86_64', self::MAC_ARM => 'darwin-aarch64', self::MAC_INTEL => 'darwin-x86_64'];

    public const LABELS = [self::ANDROID => 'Android phone', self::WINDOWS => 'Windows', self::MAC_ARM => 'Mac (Apple silicon)', self::MAC_INTEL => 'Mac (Intel)'];

    protected $fillable = [
        'platform', 'version', 'code', 'path', 'file_name', 'bytes', 'sha256', 'updater_path', 'updater_file_name', 'signature', 'notes',
        'published_at',
    ];

    /** The file the desktop updater downloads: its own archive, else the installer itself. */
    public function updaterName(): string
    {
        return $this->updater_file_name ?? $this->file_name;
    }

    protected function casts(): array
    {
        return ['code' => 'integer', 'bytes' => 'integer', 'published_at' => 'datetime'];
    }

    /** The newest build of [$platform]: the highest version code for the phone, else the newest version. */
    public static function latest(string $platform): ?self
    {
        return self::all()->where('platform', $platform)->sort(self::newestFirst(...))->first();
    }

    /** @return Collection<int, self> every build, newest first within each platform */
    public static function listed(): Collection
    {
        $order = array_flip(self::PLATFORMS);

        return self::all()
            ->sort(fn (self $a, self $b): int => ($order[$a->platform] ?? 99) <=> ($order[$b->platform] ?? 99) ?: self::newestFirst($a, $b))
            ->values();
    }

    public static function newestFirst(self $a, self $b): int
    {
        if ($a->code !== null && $b->code !== null && $a->code !== $b->code) {
            return $b->code <=> $a->code;
        }

        return version_compare(self::bare($b->version), self::bare($a->version)) ?: $b->published_at <=> $a->published_at;
    }

    /** "1.5.0-beta.1" → "1.5.0-beta.1"; a leading "v" goes. */
    public static function bare(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
