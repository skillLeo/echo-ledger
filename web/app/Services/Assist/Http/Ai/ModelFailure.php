<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

use RuntimeException;

/**
 * Why a model call did not give an answer (the relay's `ProviderError`): the app only ever gets the code and a plain
 * sentence, never a vendor's name or words.
 */
final class ModelFailure extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAVAILABLE = 'unavailable';

    public const RATE_LIMITED = 'rate_limited';

    public const TIMEOUT = 'timeout';

    public const DECLINED = 'declined';

    public const PROTOCOL = 'protocol';

    /**
     * Carries no earlier exception: a provider's own error would bring its request, and a trace holds what was passed.
     * The reason is all anyone needs.
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** The `error.code` the app gets. */
    public function code(): string
    {
        return match ($this->reason) {
            self::NOT_CONFIGURED => 'provider_not_configured',
            self::TIMEOUT => 'provider_timeout',
            self::DECLINED => 'model_declined',
            default => 'provider_unavailable',
        };
    }

    /** Whether the same request may work a little later. */
    public function retryable(): bool
    {
        return in_array($this->reason, [self::UNAVAILABLE, self::RATE_LIMITED, self::TIMEOUT, self::PROTOCOL], true);
    }

    /** Whether the provider may have taken (and billed) the request: false when it turned it away unprocessed. */
    public function billed(): bool
    {
        return ! in_array($this->reason, [self::NOT_CONFIGURED, self::RATE_LIMITED], true);
    }

    /** The sentence the app may show: no vendor name, the provider is in the log only. */
    public function plain(): string
    {
        return match ($this->reason) {
            self::NOT_CONFIGURED => 'Live Assist is not set up on the server yet. Ask your admin.',
            self::TIMEOUT => 'The model did not answer in time. Try again.',
            self::DECLINED => 'The model declined to answer this one.',
            default => 'The model is not available right now. Try again in a moment.',
        };
    }
}
