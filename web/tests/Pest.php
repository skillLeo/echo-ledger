<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Services\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the Laravel app and get a fresh in-memory schema per test.
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Unit tests are plain and fast (pure classes); they do not boot the framework.

/**
 * Ends the 48-hour undo bin (B17) for everything deleted so far and erases it, as the hourly retention run would.
 */
function emptyUndoBin(): void
{
    CaptureSession::onlyTrashed()->whereNull('media_erased_at')
        ->update(['deleted_at' => now()->subHours(CaptureSession::BIN_HOURS + 1)]);
    app(RetentionPolicy::class)->emptyBin();
}
