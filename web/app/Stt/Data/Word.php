<?php

declare(strict_types=1);

namespace App\Stt\Data;

final readonly class Word
{
    /**
     * A word below this confidence (0-1) is shown as uncertain in every app: dotted underline, a tap plays it
     * (since v1.2). The text stays as heard.
     */
    public const UNCERTAIN_BELOW = 0.5;

    public function __construct(
        public string $text,
        public int $startMs,
        public int $endMs,
        public ?float $confidence = null,
    ) {}

    /** Whether a stored confidence (a number 0-1, or nothing) marks the word uncertain. */
    public static function isUncertain(mixed $confidence): bool
    {
        return is_numeric($confidence) && (float) $confidence < self::UNCERTAIN_BELOW;
    }

    /** @return array{text: string, start_ms: int, end_ms: int, confidence: float|null} */
    public function toArray(): array
    {
        return ['text' => $this->text, 'start_ms' => $this->startMs, 'end_ms' => $this->endMs, 'confidence' => $this->confidence];
    }
}
