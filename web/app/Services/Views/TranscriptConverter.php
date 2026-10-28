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
     * @return array{lines: array<int, array<string, string>>, input_tokens: int, output_tokens: int, model: string}
     */
    public function convert(array $lines, string $language, array $views): array;
}
