<?php

declare(strict_types=1);

namespace App\Services\Health;

/**
 * Which PHP extensions are loaded, asked in one place so a test can take one away (`new PhpExtensions(['sodium'])` in
 * the container): RecordingCipher and the server health check ask here.
 */
class PhpExtensions
{
    /** @param list<string> $absent extensions to report as not loaded, whatever PHP has (tests only) */
    public function __construct(private readonly array $absent = []) {}

    public function loaded(string $extension): bool
    {
        return ! in_array($extension, $this->absent, true) && extension_loaded($extension);
    }
}
