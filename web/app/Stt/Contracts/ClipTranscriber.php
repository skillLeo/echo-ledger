<?php

declare(strict_types=1);

namespace App\Stt\Contracts;

/**
 * An engine that can listen to one short clip (a few seconds of one speaker) and answer at once: used by Double-check
 * (since v1.2) to hear again the lines the first engine was not sure of.
 */
interface ClipTranscriber
{
    /**
     * The clip's text and the audio the provider bills for it. The provider's copy is deleted when it keeps one.
     *
     * @return array{text: string, billed_ms: int}
     */
    public function transcribeClip(string $path, float $seconds, ?string $language): array;
}
