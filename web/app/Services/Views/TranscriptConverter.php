<?php

declare(strict_types=1);

namespace App\Services\Views;

/**
 * Turns transcript lines into the "Show as" views (since v1.2): the same words in Roman Urdu (Latin letters) and an
 * English translation. Text only: no audio is sent.
 */
interface TranscriptConverter
{
    /**
     * @param  array<int, string>  $lines  segment index => text
     * @param  bool  $roman  whether the Roman (Latin-letter) view is wanted too (Urdu and Arabic)
     * @return array{lines: array<int, array{roman: string|null, english: string}>, input_tokens: int, output_tokens: int, model: string}
     */
    public function convert(array $lines, string $language, bool $roman): array;
}
