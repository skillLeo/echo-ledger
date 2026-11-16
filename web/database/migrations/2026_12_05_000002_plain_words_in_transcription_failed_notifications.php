<?php

declare(strict_types=1);

use App\Console\Commands\PlainFailureNotifications;
use Illuminate\Database\Migrations\Migration;

/**
 * Since v1.8.1 (D-131): a "Transcription failed" notification said why in the engine's own words, and the console's
 * bell and Activity, the apps' Activity and their system notifications showed it. This rewrites the ones already sent
 * in plain words per error code, through `php artisan echoledger:plain-failure-notifications`, which skips any already
 * plain and so is safe to run again: run it once more after `php artisan queue:restart`, for a notification a worker
 * still running the old code sent meanwhile (ADMIN_GUIDE.md, 9d). The engine's own words stay with the transcript, for
 * the operator, so down() has nothing to put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        PlainFailureNotifications::rewrite();
    }

    public function down(): void
    {
        // Nothing to put back: the engine's own words were never meant for people and stay with each transcript.
    }
};
