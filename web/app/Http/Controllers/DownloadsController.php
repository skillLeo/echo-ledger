<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppRelease;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The downloads page and the apps' update manifests (since v1.5, docs/API.md "Public pages and app downloads"). Public
 * and throttled; builds are published with `php artisan echoledger:publish-release`.
 */
final class DownloadsController
{
    private const MIMES = [
        'apk' => 'application/vnd.android.package-archive',
        'exe' => 'application/vnd.microsoft.portable-executable',
        'msi' => 'application/x-msi',
        'dmg' => 'application/x-apple-diskimage',
        'gz' => 'application/gzip',
    ];

    public function page(): View
    {
        $all = AppRelease::listed();
        $latest = collect(AppRelease::PLATFORMS)
            ->mapWithKeys(fn (string $platform): array => [$platform => $all->firstWhere('platform', $platform)])
            ->filter();
        $older = $all->reject(fn (AppRelease $r): bool => $latest->contains(fn (AppRelease $l): bool => $l->is($r)))->values();

        return view('site.downloads', ['latest' => $latest, 'older' => $older]);
    }

    /** The newest website build of the phone app; 404 while none is published. */
    public function android(): JsonResponse
    {
        $release = AppRelease::latest(AppRelease::ANDROID);
        if ($release === null) {
            return response()->json(['message' => 'No phone build is published yet.', 'code' => 'not_found'], 404);
        }

        return response()->json([
            'version' => $release->version,
            'code' => $release->code,
            'url' => route('downloads.file', ['name' => $release->file_name]),
            'sha256' => $release->sha256,
            'bytes' => $release->bytes,
            'min_sdk' => 29,
            'notes' => $release->notes,
            'published_at' => $release->published_at->toAtomString(),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * The desktop updater's manifest (Tauri's static format): the newest version that has a signed build, with every
     * platform signed for that version. A build without a signature is on the page but never offered as an update.
     */
    public function desktop(): JsonResponse
    {
        $signed = AppRelease::query()
            ->whereIn('platform', array_keys(AppRelease::UPDATER_KEYS))
            ->whereNotNull('signature')
            ->get()
            ->sort(AppRelease::newestFirst(...))
            ->values();
        $newest = $signed->first();
        if ($newest === null) {
            return response()->json(['message' => 'No desktop update is published yet.', 'code' => 'not_found'], 404);
        }
        $builds = $signed->where('version', $newest->version);

        return response()->json([
            'version' => $newest->version,
            'notes' => $builds->pluck('notes')->filter()->first() ?? '',
            'pub_date' => $builds->max('published_at')?->toAtomString(),
            'platforms' => $builds->mapWithKeys(fn (AppRelease $r): array => [AppRelease::UPDATER_KEYS[$r->platform] => [
                'url' => route('downloads.file', ['name' => $r->updaterName()]),
                'signature' => $r->signature,
            ]])->all(),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    /** A build's file, as an attachment (resumable: ranges are honoured). */
    public function file(string $name): BinaryFileResponse
    {
        $release = AppRelease::query()->where('file_name', $name)->orWhere('updater_file_name', $name)->first();
        abort_if($release === null, 404);
        $path = $release->file_name === $name ? $release->path : (string) $release->updater_path;
        $disk = Storage::disk('downloads');
        abort_unless($disk->exists($path), 404);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return response()->download($disk->path($path), $name, [
            'Content-Type' => self::MIMES[$extension] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
