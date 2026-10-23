<?php

declare(strict_types=1);

namespace App\Stt\Data;

use DateTimeInterface;

/** Response body of POST /api/stt/token (docs/API.md). Contains short-lived tokens only. */
final readonly class StreamingGrant
{
    /**
     * @param  'cloud'|'on_device'  $mode
     * @param  list<string>  $keyterms
     * @param  list<array<string, mixed>>  $grants
     */
    public function __construct(
        public string $provider,
        public string $mode,
        public ?DateTimeInterface $expiresAt,
        public array $keyterms,
        public array $grants,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'mode' => $this->mode,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'keyterms' => $this->keyterms,
            'grants' => $this->grants,
        ];
    }
}
