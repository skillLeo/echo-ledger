<?php

declare(strict_types=1);

namespace App\Services\Assist;

use RuntimeException;
use Throwable;

/**
 * The report's model call failed (AssistReportAi::write). When the provider answered and billed the call (a refusal,
 * or an answer that was not the report's JSON), [$usage] says what it cost, so it is charged and counted as a run.
 * When the request went out and no answer came back (a timeout, a dropped connection), [$unknownCost] is true: the
 * provider may have billed it, so it is counted as a run and charged at its worst case, marked estimated.
 */
final class AssistReportFailed extends RuntimeException
{
    /**
     * @param  array{model: string, tokens: array{input: int, cached: int, written: int, output: int}}|null  $usage
     */
    public function __construct(string $message, public readonly ?array $usage = null, ?Throwable $previous = null, public readonly bool $unknownCost = false)
    {
        parent::__construct($message, 0, $previous);
    }
}
