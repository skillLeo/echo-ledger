<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Screen;
use App\Services\Screens\ScreenDescriptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reads the text and a one-line description of a screen moment with Claude Haiku (v1.5). Once: a failure leaves both empty. */
final class DescribeScreenJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $screenId) {}

    public function handle(ScreenDescriptions $descriptions): void
    {
        $screen = Screen::query()->withoutGlobalScopes()->find($this->screenId);
        if ($screen instanceof Screen && $screen->described_at === null) {
            $descriptions->describe($screen);
        }
    }
}
