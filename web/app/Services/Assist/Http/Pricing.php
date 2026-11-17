<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

/**
 * Costs for HTTP mode in micro-dollars, as the relay computes them (services/assist-relay/src/budget.rs and text.rs),
 * from the catalog in config/assist.php (US$ per million tokens):
 *
 * - cost(): what a call cost, from the provider's counts;
 * - bound(): a call's worst case for the budget gate: upper-bound tokens, the cacheable part at the dearer of plain
 *   input and a cache write, the longest answer;
 * - modeEstimate() / modeWorst(): one suggestion in a mode, for the mode choice.
 */
class Pricing
{
    /** Tokens of a session's context when nothing better is known yet (the rules, a brief, a few lines). */
    public const TYPICAL_CONTEXT_TOKENS = 4000;

    /** Output tokens assumed for one suggestion when estimating (the relay's `expected_output_tokens`). */
    public const EXPECTED_OUTPUT_TOKENS = 160;

    /** About how many tokens [$text] costs: four ASCII characters a token, two for other scripts. */
    public static function estimateTokens(string $text): int
    {
        [$ascii, $other] = self::split($text);

        return intdiv($ascii + 3, 4) + intdiv($other + 1, 2);
    }

    /** An upper bound for [$text]: one token per non-ASCII character, one per three ASCII ones, 10 % on top. */
    public static function upperTokens(string $text): int
    {
        [$ascii, $other] = self::split($text);
        $tokens = intdiv($ascii + 2, 3) + $other;

        return max(1, $tokens + intdiv($tokens + 9, 10));
    }

    /**
     * The price card for a request of [$promptTokens] (the long-context card above its threshold).
     *
     * @return array{input: float, cache_write: float, cache_read: float, output: float}
     */
    public static function price(string $model, int $promptTokens): array
    {
        $spec = (array) config("assist.models.{$model}", []);
        $long = $spec['long_context'] ?? null;
        $card = is_array($long) && $promptTokens > (int) ($long['above_tokens'] ?? PHP_INT_MAX) ? $long : (array) ($spec['price'] ?? []);

        return [
            'input' => (float) ($card['input'] ?? 0), 'cache_write' => (float) ($card['cache_write'] ?? 0),
            'cache_read' => (float) ($card['cache_read'] ?? 0), 'output' => (float) ($card['output'] ?? 0),
        ];
    }

    /**
     * What a call cost, from the provider's counts.
     *
     * @param  array{input: int, cached: int, written: int, output: int}  $tokens
     */
    public static function cost(string $model, array $tokens): int
    {
        $price = self::price($model, $tokens['input'] + $tokens['cached'] + $tokens['written']);

        return self::micros($tokens['input'] * $price['input'] + $tokens['cached'] * $price['cache_read']
            + $tokens['written'] * $price['cache_write'] + $tokens['output'] * $price['output']);
    }

    /** A call's worst case: [$contextTokens] upper-bound input, [$cacheable] of it possibly a cache write, [$output] out. */
    public static function bound(string $model, int $contextTokens, int $cacheable, int $output): int
    {
        if (! is_array(config("assist.models.{$model}"))) {
            return PHP_INT_MAX >> 4;
        }
        $price = self::price($model, $contextTokens);
        $cacheable = min($cacheable, $contextTokens);

        return self::micros($cacheable * max($price['input'], $price['cache_write']) + ($contextTokens - $cacheable) * $price['input']
            + $output * $price['output']);
    }

    /** A suggestion's likely cost in [$mode]: its primary model, a cold cache, the usual answer; plus its upgrade's share. */
    public function modeEstimate(string $mode, ?int $contextTokens = null): int
    {
        $plan = (array) config("assist.modes.{$mode}", []);
        $tokens = $contextTokens ?? self::TYPICAL_CONTEXT_TOKENS;
        $one = function (string $model) use ($tokens): int {
            $price = self::price($model, $tokens);

            return self::micros($tokens * $price['input'] + self::EXPECTED_OUTPUT_TOKENS * $price['output']);
        };
        $micros = $one((string) ($plan['primary'] ?? ''));
        if (is_string($plan['upgrade'] ?? null)) {
            $micros += (int) ceil($one($plan['upgrade']) * (float) config('assist.http.upgrade_share', 0.3));
        }

        return $micros;
    }

    /** A suggestion's worst case in [$mode]: its primary and its fallback (both can bill), the mode's longest answer. */
    public function modeWorst(string $mode, ?int $contextTokens = null): int
    {
        $plan = (array) config("assist.modes.{$mode}", []);
        $tokens = $contextTokens ?? self::TYPICAL_CONTEXT_TOKENS;
        $output = (int) ($plan['max_output_tokens'] ?? 500);
        $total = 0;
        foreach (array_filter([$plan['primary'] ?? null, $plan['fallback'] ?? null], 'is_string') as $model) {
            $total += self::bound($model, $tokens, $tokens, $output);
        }

        return $total;
    }

    /** @return array{0: int, 1: int} ASCII and other characters */
    private static function split(string $text): array
    {
        $chars = mb_strlen($text, 'UTF-8');
        $other = $chars - strlen((string) preg_replace('/[^\x00-\x7F]/u', '', $text));
        if ($other < 0) {
            $other = 0;
        }

        return [$chars - $other, $other];
    }

    private static function micros(float $value): int
    {
        return is_finite($value) && $value > 0 ? (int) ceil($value) : 0;
    }
}
