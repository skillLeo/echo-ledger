<?php

declare(strict_types=1);

namespace App\Stt\Data;

/** A verified provider callback. */
final readonly class WebhookEnvelope
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $idempotencyKey,
        public ?string $transcriptRef,   // our transcripts.id (from rid / webhook metadata)
        public ?string $jobId,           // provider job id, cross-checked against provider_job_id
        public string $status,           // completed | error | other
        public array $payload,
        public ?string $error = null,
    ) {}
}
