<?php

declare(strict_types=1);

namespace App\Stt\Exceptions;

/**
 * The engine has no room for this request right now (its rate limits, as we count them or as it said): not a failure.
 * The transcript waits [$retryAfterSeconds] and the same engine goes on where it stopped (App\Services\EngineWait).
 */
final class ProviderBusy extends SttException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}
