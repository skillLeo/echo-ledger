<?php

declare(strict_types=1);

namespace App\Services\Titles;

/** Writes a recording's automatic title from its transcript (since v1.5, Phase 1C). Text only: no audio is sent. */
interface TitleWriter
{
    /** @return array{title: string, input_tokens: int, output_tokens: int, model: string} */
    public function title(string $transcript, string $language): array;
}
