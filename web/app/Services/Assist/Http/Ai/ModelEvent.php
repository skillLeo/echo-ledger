<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

/**
 * One thing a model stream gives: the next piece of the answer, or (once, usually at the end) the token counts as the
 * provider reports them. `input` is the uncached rest only.
 */
final readonly class ModelEvent
{
    /** @param  array{input: int, cached: int, written: int, output: int}|null  $usage */
    private function __construct(public ?string $text, public ?array $usage) {}

    public static function text(string $text): self
    {
        return new self($text, null);
    }

    /** @param  array{input: int, cached: int, written: int, output: int}  $usage */
    public static function usage(array $usage): self
    {
        return new self(null, $usage);
    }
}
