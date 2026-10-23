<?php

declare(strict_types=1);

namespace App\Stt\Data;

/** Shared transcript unit: channel 1 = "Me" (mic, left), channel 2 = "Client" (system, right). */
final readonly class Segment
{
    /** @param list<Word> $words */
    public function __construct(
        public int $channel,
        public string $speaker,
        public int $startMs,
        public int $endMs,
        public string $text,
        public array $words = [],
    ) {}

    /** @return array{channel: int, speaker: string, start_ms: int, end_ms: int, text: string, words: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'speaker' => $this->speaker,
            'start_ms' => $this->startMs,
            'end_ms' => $this->endMs,
            'text' => $this->text,
            'words' => array_map(fn (Word $w): array => $w->toArray(), $this->words),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $words = [];
        foreach ((array) ($data['words'] ?? []) as $w) {
            if (is_array($w)) {
                $words[] = new Word((string) ($w['text'] ?? ''), (int) ($w['start_ms'] ?? 0), (int) ($w['end_ms'] ?? 0),
                    isset($w['confidence']) ? (float) $w['confidence'] : null);
            }
        }

        return new self((int) ($data['channel'] ?? 1), (string) ($data['speaker'] ?? 'Speaker'), (int) ($data['start_ms'] ?? 0),
            (int) ($data['end_ms'] ?? 0), (string) ($data['text'] ?? ''), $words);
    }
}
