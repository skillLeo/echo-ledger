<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * GET /api/config/share-bars (since v1.8.1): the window patterns that mean "a screen share is running", from
 * resources/data/share-bars.json, the one copy (the desktop app ships the same file as its fallback). A strong ETag of
 * the file's bytes; a matching If-None-Match is answered 304. Private: the answer needs a device token.
 */
final class ShareBarsController extends Controller
{
    public const FILE = 'data/share-bars.json';

    public function __invoke(Request $request): Response
    {
        $raw = self::raw();
        $etag = self::etag($raw);
        $headers = ['ETag' => $etag, 'Cache-Control' => 'max-age=300, private'];

        if (self::matches($request->header('If-None-Match'), $etag)) {
            return response('', 304, $headers);
        }

        /** @var array{version: int, updated_at: string, patterns: list<array<string, string>>} $file */
        $file = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $body = json_encode([
            'version' => $file['version'],
            'updated_at' => $file['updated_at'],
            'patterns' => $file['patterns'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response($body, 200, ['Content-Type' => 'application/json', ...$headers]);
    }

    public static function raw(): string
    {
        $raw = @file_get_contents(resource_path(self::FILE));
        if ($raw === false) {
            throw new RuntimeException('resources/'.self::FILE.' is missing.');
        }

        return $raw;
    }

    /** A strong validator: the same bytes give the same tag on every server. */
    public static function etag(string $raw): string
    {
        return '"'.substr(hash('sha256', $raw), 0, 32).'"';
    }

    /** If-None-Match compares weakly (RFC 9110 13.1.2): `*`, or any listed tag with or without `W/`. */
    private static function matches(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if ($tag === '*' || $tag === $etag || $tag === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }
}
