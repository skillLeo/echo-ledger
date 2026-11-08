<?php

declare(strict_types=1);

namespace App\Services\Views;

/**
 * Turns transcript lines into the "Show as" views (since v1.2): Roman Urdu (Latin letters), an English translation and
 * an Urdu translation. Text only: no audio is sent.
 */
interface TranscriptConverter
{
    /**
     * @param  array<int, string>  $lines  segment index => text
     * @param  list<string>  $views  the views wanted (keys of TranscriptViews::VIEWS)
     *                               `misheard` (since v1.5, Phase 1C, optional): per line, the words the model believes were misheard (`from` as in the
     *                               line, `to` what was probably said); the views keep them as they are, and they become suggested fixes.
     * @return array{lines: array<int, array<string, string>>, misheard?: array<int, list<array{from: string, to: string}>>, input_tokens: int, output_tokens: int, model: string}
     */
    public function convert(array $lines, string $language, array $views): array;
}
