<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use App\Services\Assist\AssistMonitor;
use App\Services\Assist\RelaySecret;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The internal Live Assist API (relay → server, docs/API_ASSIST.md "Internal"): every request carries
 * `X-Assist-Timestamp` (unix seconds, within ±60 s) and `X-Assist-Signature`, the lowercase hex HMAC-SHA256 of
 * `timestamp\nMETHOD\npath\nbody` with the `assist_relay` secret (or the previous one during a rotation). A repeat
 * within the minute is let through: the reads change nothing, and every write is idempotent (usage once per
 * suggestion, the rest replaced), so two relays asking the same second are never refused. A device token opens
 * nothing here.
 */
final class VerifyAssistRelay
{
    public const WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (string) $request->header('X-Assist-Timestamp', '');
        $signature = (string) $request->header('X-Assist-Signature', '');
        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        $fresh = preg_match('/^\d{1,12}$/', $timestamp) === 1 && abs(now()->getTimestamp() - (int) $timestamp) <= self::WINDOW_SECONDS;
        if (! $fresh || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1
            || ! app(RelaySecret::class)->verifies($timestamp, $request->getMethod(), $path, $request->getContent(), $signature)) {
            throw new ApiError(401, 'unauthenticated', 'Unauthenticated.');
        }
        // Since B4: the admin's "last contact from the relay" (a time only, at most once a minute).
        AssistMonitor::sawRelay();

        return $next($request);
    }
}
