<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CaptureSession;
use App\Services\Accuracy\AccuracyLab;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Accuracy Lab (since v1.2): scores a recording's comparison runs against its reference transcript. */
final class ScoreAccuracyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public readonly string $sessionId) {}

    public function handle(AccuracyLab $lab): void
    {
        $session = CaptureSession::query()->withoutGlobalScopes()->find($this->sessionId);
        if ($session !== null) {
            $lab->scoreSession($session);
        }
    }
}
