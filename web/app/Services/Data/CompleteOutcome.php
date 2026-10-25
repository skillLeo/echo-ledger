<?php

declare(strict_types=1);

namespace App\Services\Data;

/**
 * Result of `POST /api/recordings/{id}/complete` (docs/API.md): chunks still missing, assembly running on the queue,
 * the recording stored and verified, or the whole file failed verification (the parts were discarded).
 */
final readonly class CompleteOutcome
{
    public const INCOMPLETE = 'incomplete';

    public const ASSEMBLING = 'assembling';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /**
     * @param  self::INCOMPLETE|self::ASSEMBLING|self::READY|self::FAILED  $state
     * @param  list<int>  $missing  chunk indexes still to send (only for `incomplete`)
     */
    public function __construct(
        public string $state,
        public array $missing = [],
    ) {}

    /** @param  list<int>  $missing */
    public static function incomplete(array $missing): self
    {
        return new self(self::INCOMPLETE, $missing);
    }

    public static function assembling(): self
    {
        return new self(self::ASSEMBLING);
    }

    public static function ready(): self
    {
        return new self(self::READY);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }
}
