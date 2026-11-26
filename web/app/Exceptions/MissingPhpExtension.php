<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * PHP lacks an extension the server needs (web/DEPLOY.md, Requirements). Thrown before the first call into it, so the
 * log names the extension instead of an undefined constant or function (W-INC-1124: sodium went missing after a
 * hosting change, and every upload failed on RecordingCipher's first line). A fault of the server, never of an upload.
 */
final class MissingPhpExtension extends RuntimeException
{
    public string $extension = '';

    public static function named(string $extension, string $needs = 'recordings cannot be encrypted or played'): self
    {
        $missing = new self("The PHP extension \"{$extension}\" is not loaded, so {$needs}. Turn it on for both the web PHP and the "
            .'CLI PHP (web/DEPLOY.md, Requirements), then run `php artisan echoledger:health`.');
        $missing->extension = $extension;

        return $missing;
    }
}
