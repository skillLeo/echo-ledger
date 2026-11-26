<?php

declare(strict_types=1);

namespace App\Services\Titles;

/** Writes a recording's automatic title from its transcript (since v1.5, Phase 1C). Text only: no audio is sent. */
interface TitleWriter
{
    /**
     * [$roman] (since U19): the title is written in Roman Urdu (Urdu in English letters), never in Urdu letters, as
     * for a recording that opens in its Roman Urdu view.
     *
     * @return array{title: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function title(string $transcript, string $language, bool $roman = false): array;
}
