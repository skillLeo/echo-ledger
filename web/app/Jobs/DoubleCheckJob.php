<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\DoubleCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Double-check (since v1.2): a second engine hears again the lines of a finished transcript the first engine was not
 * sure of ([DoubleCheck]). Runs once: a clip that was sent may already be billed, so nothing is retried.
 */
final class DoubleCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $transcriptId) {}

    public function handle(DoubleCheck $check): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript !== null) {
            $check->run($transcript);
        }
    }
}
