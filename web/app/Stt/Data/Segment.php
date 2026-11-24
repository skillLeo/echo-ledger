<?php

declare(strict_types=1);

namespace App\Stt\Data;

use App\Stt\LanguageChoice;

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
        /** The engine was not sure of this line (it is kept as heard; the apps underline it, a tap plays it). */
        public bool $uncertain = false,
        /**
         * Since 2.0.0-o: the language this line was heard in (one of ours, `ur`, `en`, `ar`…) when the engine said so;
         * null when it did not. The apps can skip a language's lines.
         */
        public ?string $language = null,
    ) {}

    /** The same line heard in [$language] (null: not known). */
    public function withLanguage(?string $language): self
    {
        return new self($this->channel, $this->speaker, $this->startMs, $this->endMs, $this->text, $this->words, $this->uncertain, $language);
    }

    /** @return array{channel: int, speaker: string, start_ms: int, end_ms: int, text: string, words: list<array<string, mixed>>, uncertain?: true, language?: string} */
    public function toArray(): array
    {
        $out = [
            'channel' => $this->channel,
            'speaker' => $this->speaker,
            'start_ms' => $this->startMs,
            'end_ms' => $this->endMs,
            'text' => $this->text,
            'words' => array_map(fn (Word $w): array => $w->toArray(), $this->words),
        ];
        if ($this->uncertain) {
            $out['uncertain'] = true;
        }
        if ($this->language !== null) {
            $out['language'] = $this->language;
        }

        return $out;
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
            (int) ($data['end_ms'] ?? 0), (string) ($data['text'] ?? ''), $words, (bool) ($data['uncertain'] ?? false),
            LanguageChoice::fromDetected($data['language'] ?? null));
    }
}
