<?php

declare(strict_types=1);

namespace App\Stt\Data;

final readonly class SubmitResult
{
    private function __construct(
        public string $state, // submitted | completed | awaiting_device
        public ?string $jobId = null,
        public ?string $transcriptId = null,
        public ?NormalizedTranscript $result = null,
    ) {}

    public static function submitted(string $jobId, ?string $providerTranscriptId = null): self
    {
        return new self('submitted', $jobId, $providerTranscriptId);
    }

    /** A result in the same call; [$providerTranscriptId] names the copy the provider keeps, when it keeps one. */
    public static function completed(NormalizedTranscript $result, ?string $jobId = null, ?string $providerTranscriptId = null): self
    {
        return new self('completed', $jobId, $providerTranscriptId, $result);
    }

    public static function awaitingDevice(): self
    {
        return new self('awaiting_device');
    }
}
