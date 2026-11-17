<?php

declare(strict_types=1);

namespace App\Services\Assist;

/**
 * The words of a Live Assist report (v1.9 L7): the summary, each answer's verdict and a better one, mistakes against
 * the facts ledger, promises, three grammar fixes, action items and a follow-up email. Everything it reads comes from
 * the session; it is never used for training.
 */
interface AssistReportAi
{
    /**
     * @param  array<string, mixed>  $input  the session as AssistReport::aiInput() gives it
     * @return array{data: array<string, mixed>, model: string, tokens: array{input: int, cached: int, written: int, output: int}}
     *
     * @throws \RuntimeException when the provider is not set up, refuses or cannot be reached (a plain message)
     */
    public function write(array $input): array;
}
