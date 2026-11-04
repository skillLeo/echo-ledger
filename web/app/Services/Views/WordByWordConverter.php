<?php

declare(strict_types=1);

namespace App\Services\Views;

/**
 * Since v1.5: Roman Urdu word by word, for the lines whose first Roman Urdu lost words. Each line goes in as its list
 * of words and comes back with exactly one transliteration per word, in order, so a word can only go missing when the
 * count is wrong, which the caller sees and then keeps the original line.
 */
interface WordByWordConverter
{
    /**
     * @param  array<int, list<string>>  $lines  segment index => its words (a marker such as "[unclear: …]" is one word)
     * @return array{lines: array<int, list<string>>, input_tokens: int, output_tokens: int, model: string}
     */
    public function romanWords(array $lines, string $language): array;
}
