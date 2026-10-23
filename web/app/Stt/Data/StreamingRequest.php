<?php

declare(strict_types=1);

namespace App\Stt\Data;

use App\Enums\SttUseCase;

final readonly class StreamingRequest
{
    /**
     * @param  list<'me'|'client'>  $channels
     * @param  list<string>  $keyterms  already sanitized client keyterms
     */
    public function __construct(
        public SttUseCase $useCase,
        public array $channels,
        public int $sampleRate,
        public ?string $language,
        public array $keyterms,
    ) {}
}
