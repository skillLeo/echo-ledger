<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

use App\Services\Assist\Http\Prompt\Prompt;

/**
 * One streaming completion asked of a model: the prompt's segments, the model's catalog entry (provider, options and
 * its first-token timeout) and the answer's limit.
 */
final readonly class ModelRequest
{
    /**
     * @param  array<string, mixed>  $options  the model's `options` from the catalog, passed on as they are
     */
    public function __construct(
        public string $model,
        public string $provider,
        public Prompt $prompt,
        public int $maxOutputTokens,
        public array $options,
        public float $firstTokenSeconds,
        public float $stallSeconds,
    ) {}

    /** The request for [$model] from the catalog (config/assist.php). */
    public static function for(string $model, Prompt $prompt, int $maxOutputTokens): self
    {
        $spec = (array) config("assist.models.{$model}", []);

        return new self(
            $model,
            (string) ($spec['provider'] ?? 'anthropic'),
            $prompt,
            $maxOutputTokens,
            (array) ($spec['options'] ?? []),
            ((int) ($spec['first_token_timeout_ms'] ?? 5000)) / 1000,
            ((int) config('assist.http.stall_timeout_ms', 10000)) / 1000,
        );
    }
}
