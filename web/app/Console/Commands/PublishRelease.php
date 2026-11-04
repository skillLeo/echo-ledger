<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppRelease;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

/**
 * Puts an app build on the downloads page and in the update manifests (since v1.5, docs/API.md "Public pages and app
 * downloads"): the file is copied to the "downloads" disk, measured and hashed; publishing the same platform and
 * version again replaces it. Older builds stay listed.
 */
final class PublishRelease extends Command
{
    protected $signature = 'echoledger:publish-release
        {file : The build: the APK, the Windows installer, or the Mac disk image}
        {--platform= : android, windows, macos-arm or macos-intel}
        {--app-version= : The version people see, e.g. 1.5.0 or 1.5.0-beta.2}
        {--code= : The phone build\'s version code (needed for android)}
        {--notes= : What changed, in a sentence or two}
        {--updater-file= : Mac only: the .app.tar.gz the desktop updater installs}
        {--signature= : Desktop only: the updater signature (.sig) of the installer, or of --updater-file on a Mac}';

    protected $description = 'Publish an app build on the downloads page and in the update manifests';

    /** What each platform's people download (the updater's Mac archive is checked separately). */
    private const EXTENSIONS = [
        AppRelease::ANDROID => ['apk'],
        AppRelease::WINDOWS => ['exe', 'msi'],
        AppRelease::MAC_ARM => ['dmg'],
        AppRelease::MAC_INTEL => ['dmg'],
    ];

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $platform = (string) $this->option('platform');
        $version = AppRelease::bare((string) $this->option('app-version'));

        if (! in_array($platform, AppRelease::PLATFORMS, true)) {
            return $this->refuse('Unknown --platform. Use one of: '.implode(', ', AppRelease::PLATFORMS).'.');
        }
        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]{1,20})?$/', $version) !== 1) {
            return $this->refuse('--app-version must look like 1.5.0 or 1.5.0-beta.2.');
        }
        if (! is_file($file) || ! is_readable($file) || filesize($file) === 0) {
            return $this->refuse("No readable build at {$file}.");
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (! in_array($extension, self::EXTENSIONS[$platform], true)) {
            return $this->refuse("A {$platform} build is a ".implode(' or ', array_map(fn (string $e): string => ".{$e}", self::EXTENSIONS[$platform])).' file.');
        }

        $code = null;
        if ($platform === AppRelease::ANDROID) {
            $code = filter_var($this->option('code'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($code === false) {
                return $this->refuse('A phone build needs --code, its version code (a whole number above 0).');
            }
        }

        $updater = $this->option('updater-file');
        if ($updater !== null && ! in_array($platform, [AppRelease::MAC_ARM, AppRelease::MAC_INTEL], true)) {
            return $this->refuse('--updater-file is only for Mac builds (Windows updates with its installer).');
        }
        if ($updater !== null && (! is_file((string) $updater) || ! str_ends_with(strtolower((string) $updater), '.app.tar.gz'))) {
            return $this->refuse('--updater-file must be the build\'s .app.tar.gz.');
        }

        $signature = null;
        if ($this->option('signature') !== null) {
            if ($platform === AppRelease::ANDROID) {
                return $this->refuse('Phone builds have no updater signature (Android checks the APK\'s own signing).');
            }
            $sigPath = (string) $this->option('signature');
            $signature = is_file($sigPath) ? trim((string) file_get_contents($sigPath)) : '';
            if ($signature === '' || strlen($signature) > 4000) {
                return $this->refuse("The signature file {$sigPath} is missing or not an updater signature.");
            }
        }

        $suffix = match ($platform) {
            AppRelease::ANDROID => '',
            AppRelease::WINDOWS => '-setup',
            AppRelease::MAC_ARM => '-mac-apple-silicon',
            AppRelease::MAC_INTEL => '-mac-intel',
        };
        $name = "EchoLedger-{$version}{$suffix}.{$extension}";
        $updaterName = $updater !== null ? "EchoLedger-{$version}{$suffix}.app.tar.gz" : null;

        $existing = AppRelease::query()->where('platform', $platform)->where('version', $version)->first();
        foreach (array_filter([$name, $updaterName]) as $wanted) {
            $taken = AppRelease::query()->where(fn ($q) => $q->where('file_name', $wanted)->orWhere('updater_file_name', $wanted))
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))->exists();
            if ($taken) {
                return $this->refuse("Another build already uses the name {$wanted}.");
            }
        }

        $disk = Storage::disk('downloads');
        $path = $disk->putFileAs($platform, new File($file), $name);
        $updaterPath = $updater !== null ? $disk->putFileAs($platform, new File((string) $updater), (string) $updaterName) : null;
        if ($path === false || $updaterPath === false) {
            return $this->refuse('The build could not be copied to the downloads folder.');
        }

        $release = $existing ?? new AppRelease(['platform' => $platform, 'version' => $version]);
        $old = array_filter([$release->path ?? null, $release->updater_path ?? null]);
        $release->fill([
            'code' => $code === null ? null : (int) $code,
            'path' => $path,
            'file_name' => $name,
            'bytes' => (int) filesize($file),
            'sha256' => (string) hash_file('sha256', $file),
            'updater_path' => $updaterPath,
            'updater_file_name' => $updaterName,
            'signature' => $signature,
            'notes' => $this->option('notes') !== null ? mb_substr(trim((string) $this->option('notes')), 0, 2000) : null,
            'published_at' => now(),
        ])->save();
        foreach (array_diff($old, array_filter([$path, $updaterPath])) as $stale) {
            $disk->delete($stale);
        }

        $this->table(['Platform', 'Version', 'Code', 'Size', 'SHA-256', 'Link'], [[
            $platform, $version, $release->code ?? '–', number_format($release->bytes).' bytes', $release->sha256,
            route('downloads.file', ['name' => $name]),
        ]]);
        if (in_array($platform, [AppRelease::WINDOWS, AppRelease::MAC_ARM, AppRelease::MAC_INTEL], true) && $signature === null) {
            $this->warn('No --signature: the build is on the downloads page, but the desktop updater will not offer it.');
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
