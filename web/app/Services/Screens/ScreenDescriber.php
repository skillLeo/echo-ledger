<?php

declare(strict_types=1);

namespace App\Services\Screens;

/** Reads a screen moment's picture: the text on it and one sentence about what it shows (v1.5). */
interface ScreenDescriber
{
    /**
     * @param  string  $image  the picture's bytes (WebP, PNG or JPEG)
     * @return array{ocr_text: string, description: string, input_tokens: int, output_tokens: int, model: string}
     */
    public function describe(string $image, string $mime): array;
}
