<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use LogicException;

/**
 * The recordings disk on object storage. A bucket has no local paths: Laravel's own `path()` would answer with the
 * object's key, which a careless `fopen()` then opens (or creates, in plaintext) next to the running script. It stops
 * here instead; everything goes through RecordingFiles.
 */
final class ObjectStorageDisk extends FilesystemAdapter
{
    /** @param  string  $path */
    public function path($path): string
    {
        throw new LogicException('The recordings disk is object storage and has no local paths: use RecordingFiles.');
    }
}
