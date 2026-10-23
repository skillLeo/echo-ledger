<?php

declare(strict_types=1);

namespace App\Stt\Data;

final readonly class Word
{
    public function __construct(
        public string $text,
        public int $startMs,
        public int $endMs,
        public ?float $confidence = null,
    ) {}

    /** @return array{text: string, start_ms: int, end_ms: int, confidence: float|null} */
    public function toArray(): array
    {
        return ['text' => $this->text, 'start_ms' => $this->startMs, 'end_ms' => $this->endMs, 'confidence' => $this->confidence];
    }
}
