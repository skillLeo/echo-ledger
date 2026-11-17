<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

/**
 * How long a model may stay silent: until its first word the model's own first-token timeout counts from the request
 * (bytes that carry no word do not reset it); after the first word, a stream that sends nothing for the stall timeout
 * has failed (services/assist-relay/src/session.rs `spawn_attempt`).
 */
final class Deadline
{
    private float $start;

    private float $last;

    private bool $seen = false;

    public function __construct(public readonly float $firstSeconds, public readonly float $stallSeconds)
    {
        $this->start = $this->last = microtime(true);
    }

    /** Bytes arrived. */
    public function data(): void
    {
        $this->last = microtime(true);
    }

    /** The first word arrived: from now on the stall timeout counts. */
    public function wordSeen(): void
    {
        $this->seen = true;
        $this->last = microtime(true);
    }

    public function exceeded(): bool
    {
        $now = microtime(true);

        return $this->seen ? $now - $this->last > $this->stallSeconds : $now - $this->start > $this->firstSeconds;
    }
}
