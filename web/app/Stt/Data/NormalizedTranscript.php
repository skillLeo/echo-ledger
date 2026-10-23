<?php

declare(strict_types=1);

namespace App\Stt\Data;

final readonly class NormalizedTranscript
{
    /**
     * @param  list<Segment>  $segments  sorted by start time
     * @param  array<string, mixed>  $meta  provider facts worth keeping (model used, channels, warnings) — never raw audio/text dumps
     */
    public function __construct(
        public string $text,
        public array $segments,
        public ?string $language = null,
        public array $meta = [],
    ) {}

    /** @return list<array<string, mixed>> */
    public function segmentsArray(): array
    {
        return array_map(fn (Segment $s): array => $s->toArray(), $this->segments);
    }
}
