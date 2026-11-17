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
     * @throws AssistReportFailed when the provider is not set up, refuses, cannot be reached or does not answer with the
     *                            report (a plain message, and what the call cost when it was billed)
     */
    public function write(array $input): array;

    /**
     * Everything a call with [$input] sends as text (the instructions, the input and the answer's schema), for the
     * run's worst-case cost.
     *
     * @param  array<string, mixed>  $input
     */
    public function promptText(array $input): string;
}
