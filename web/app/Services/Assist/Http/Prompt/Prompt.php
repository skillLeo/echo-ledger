<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Prompt;

use App\Services\Assist\Http\Pricing;

/**
 * What one request sends, segment by segment (services/assist-relay/src/context.rs `Context`): the system part and the
 * user message, cut at the blocks so a provider can keep the start cached. `cache` marks a breakpoint after a segment.
 */
final class Prompt
{
    /** @param  list<array{kind: string, role: string, text: string, tokens: int, cache: bool}>  $segments */
    public function __construct(public readonly array $segments) {}

    /** Estimated input tokens. */
    public function tokens(): int
    {
        return array_sum(array_column($this->segments, 'tokens'));
    }

    /** An upper bound of the input tokens, for budget bounds (Pricing::upperTokens). */
    public function upperTokens(): int
    {
        return array_sum(array_map(fn (array $s): int => Pricing::upperTokens($s['text']), $this->segments));
    }

    /** The upper bound of the part up to the last cache breakpoint. */
    public function upperCacheableTokens(): int
    {
        $last = null;
        foreach ($this->segments as $i => $segment) {
            if ($segment['cache']) {
                $last = $i;
            }
        }

        return $last === null ? 0 : array_sum(array_map(fn (array $s): int => Pricing::upperTokens($s['text']), array_slice($this->segments, 0, $last + 1)));
    }

    /** The whole text, as one string. */
    public function text(): string
    {
        return implode('', array_column($this->segments, 'text'));
    }

    /** The text of one role's segments, in order. */
    public function role(string $role): string
    {
        return implode('', array_map(fn (array $s): string => $s['text'], array_filter($this->segments, fn (array $s): bool => $s['role'] === $role)));
    }
}
