<?php

declare(strict_types=1);

namespace App\Services\Assist;

/**
 * The model behind "Ask over the session" (v1.9 L7): a question about one call, answered from its numbered lines, its
 * report and its facts, naming the lines it rests on. Never used for training.
 */
interface AssistAskAi
{
    /**
     * @param  array<string, mixed>  $input  as AssistAsk builds it (the question, the lines, the summary, the facts)
     * @return array{data: array<string, mixed>, model: string, tokens: array{input: int, cached: int, written: int, output: int}}
     *
     * @throws AssistReportFailed when the provider is not set up, refuses, cannot be reached or does not answer as asked
     */
    public function ask(array $input): array;

    /**
     * Everything a call with [$input] sends as text, for its worst-case cost.
     *
     * @param  array<string, mixed>  $input
     */
    public function promptText(array $input): string;
}
