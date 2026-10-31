<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ClientFile;
use App\Services\ClientAi\ClientWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reads the text of a file added to a client workspace (v1.4, P4), then refreshes the brief. */
final class ExtractClientFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $fileId) {}

    public function handle(ClientWorkspace $workspace): void
    {
        $file = ClientFile::query()->withoutGlobalScopes()->find($this->fileId);
        if ($file !== null && $file->status === 'extracting') {
            $workspace->extract($file);
        }
    }
}
