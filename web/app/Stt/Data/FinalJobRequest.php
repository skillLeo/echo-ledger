<?php

declare(strict_types=1);

namespace App\Stt\Data;

use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;

/** Everything a driver needs to transcribe a session. Paths point at decrypted private temp files. */
final readonly class FinalJobRequest
{
    /** @param list<string> $keyterms */
    public function __construct(
        public Transcript $transcript,
        public CaptureSession $session,
        public Recording $primary,
        public string $primaryPath,
        public ?string $channel1Path,
        public ?string $channel2Path,
        public ?string $channel1Mime,
        public ?string $channel2Mime,
        public array $keyterms,
        public ?string $language,
        public ?string $webhookUrl,
    ) {}

    public function isStereo(): bool
    {
        return $this->primary->channels >= 2;
    }

    public function hasChannelArtifacts(): bool
    {
        return $this->channel1Path !== null && $this->channel2Path !== null;
    }
}
