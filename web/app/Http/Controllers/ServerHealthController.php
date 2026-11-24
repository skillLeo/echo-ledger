<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Health\ServerHealth;
use Illuminate\Http\JsonResponse;

/**
 * The web PHP's own health check, for deploy/check-php.sh (W-INC-1124): the CLI PHP runs `echoledger:health`, and only
 * a request shows what the web PHP has. Public, so it says ok or not and nothing more; the admin dashboard says what.
 */
final class ServerHealthController
{
    public function __invoke(ServerHealth $health): JsonResponse
    {
        $ok = ServerHealth::healthy($health->check());

        return response()->json(['ok' => $ok], $ok ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
