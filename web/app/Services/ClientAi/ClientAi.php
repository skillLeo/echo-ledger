<?php

declare(strict_types=1);

namespace App\Services\ClientAi;

/**
 * What a client workspace asks of Claude (v1.4, P4). Nothing is trained: each answer comes only from what one request
 * carries (the client's brief, memory, files and transcripts). Every method reports the tokens it used, for the
 * client's AI budget.
 *
 * @phpstan-type Usage array{input_tokens: int, output_tokens: int, model: string}
 */
interface ClientAi
{
    /**
     * The notes of one call: only sections with content, each item with the indexes of the lines it comes from.
     *
     * @param  array<string, mixed>  $brief  the client's brief sections (may be empty)
     * @param  list<array{i: int, t: string, speaker: string, text: string}>  $lines
     * @return array{sections: array<string, list<array{text: string, lines: list<int>, verify: bool, kind?: string}>>, input_tokens: int, output_tokens: int, model: string}
     */
    public function callNotes(string $clientName, array $brief, array $lines, string $language): array;

    /**
     * A one-page brief from the client's files (PDFs as they are, other files as their text) or, with none, from the
     * notes of its calls so far.
     *
     * @param  list<array{title: string, pdf?: string, text?: string}>  $documents  pdf = the file's bytes
     * @param  list<string>  $callNotes  the latest call notes as plain text, newest last
     * @return array{sections: array<string, mixed>, input_tokens: int, output_tokens: int, model: string}
     */
    public function brief(string $clientName, array $documents, array $callNotes): array;

    /**
     * The text of a PDF, with "=== Page N ===" before each page.
     *
     * @return array{text: string, pages: int, input_tokens: int, output_tokens: int, model: string}
     */
    public function pdfText(string $pdf, string $name): array;

    /**
     * A short answer from the passages only, with what it cites: the passage and its block (line or paragraph).
     *
     * @param  array<string, mixed>  $brief
     * @param  list<string>  $memory  memory lines as text
     * @param  list<array{title: string, blocks: list<string>}>  $passages
     * @return array{answer: string, citations: list<array{passage: int, block: int, quote: string}>, input_tokens: int, output_tokens: int, model: string}
     */
    public function ask(string $clientName, array $brief, array $memory, array $passages, string $question, string $language): array;
}
