<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ClientFile;
use App\Services\ClientAi\ClientWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Reads the text of a file added to a client workspace (v1.4, P4), then refreshes the brief. */
final class ExtractClientFileJob implements ShouldQueue
{
    use Queueable;

    /** Only reading the stored file can throw out of extract(): reading a PDF, which is billed, ends in its own catch. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 600;

    public function __construct(public readonly string $fileId) {}

    public function handle(ClientWorkspace $workspace): void
    {
        $file = ClientFile::query()->withoutGlobalScopes()->find($this->fileId);
        if ($file !== null && $file->status === 'extracting') {
            $workspace->extract($file);
        }
    }

    /** Every try failed: the file says so, and its page stops waiting for it. The exception goes to the log. */
    public function failed(?Throwable $exception): void
    {
        $file = ClientFile::query()->withoutGlobalScopes()->find($this->fileId);
        if ($file !== null && $file->status === 'extracting') {
            $file->forceFill(['status' => 'failed', 'error' => ClientWorkspace::UNREADABLE])->save();
        }
    }
}
