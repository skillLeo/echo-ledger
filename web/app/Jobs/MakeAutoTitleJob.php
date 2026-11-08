<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CaptureSession;
use App\Services\Titles\AutoTitles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Automatic titles (since v1.5, Phase 1C): gives an untitled recording a short title from its transcript. Runs once. */
final class MakeAutoTitleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $sessionId) {}

    public function handle(AutoTitles $titles): void
    {
        $session = CaptureSession::withTrashed()->withoutGlobalScopes()->find($this->sessionId);
        if ($session !== null) {
            $titles->make($session);
        }
    }
}
