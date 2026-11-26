<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Health\ServerHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The web PHP's own health check, for deploy/check-php.sh (W-INC-1124): the CLI PHP runs `echoledger:health`, and only
 * a request shows what the web PHP has. Public, so it says ok or not and nothing more; the admin dashboard says what.
 */
final class ServerHealthController
{
    public function __invoke(ServerHealth $health): JsonResponse
    {
        $result = $health->check();
        $ok = ServerHealth::healthy($result);
        // Since W-INC-1125: what the web PHP lacks, for the scheduled run's email (which asks here, and only sees 503).
        $ok ? Cache::forget(ServerHealth::WEB) : Cache::put(ServerHealth::WEB, [...$result, 'php' => PHP_VERSION], now()->addHour());

        return response()->json(['ok' => $ok], $ok ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
