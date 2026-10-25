<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Shared-hosting scheduler. One cron entry drives everything (web/DEPLOY.md):
|   * * * * * cd /path/to/web && php artisan schedule:run >> /dev/null 2>&1
| The queue worker exits when empty; `withoutOverlapping(15)` keeps one worker even if a long sync job runs.
*/

Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=900')
    ->everyMinute()
    ->withoutOverlapping(15)
    ->runInBackground();

Schedule::command('stt:reconcile')->everyFiveMinutes()->withoutOverlapping(10);

// Recordings left in "assembling" by a request or worker that died (docs/API.md "complete").
Schedule::command('recordings:recover')->everyFiveMinutes()->withoutOverlapping(10);

// Open sessions (status "recording") never finished within 6 hours become tombstones.
Schedule::command('sessions:sweep-open')->everyFifteenMinutes()->withoutOverlapping(10);

Schedule::command('model:prune')->daily()->withoutOverlapping(60);

Schedule::command('sanctum:prune-expired --hours=24')->daily();
