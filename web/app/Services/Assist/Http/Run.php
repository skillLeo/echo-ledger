<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistUsage;
use App\Services\Assist\Http\Prompt\Prompt;

/**
 * One suggestion while its request is writing it (services/assist-relay/src/session.rs `Active`): the model now asked,
 * the words so far, the provider's counts, what an attempt given up for the fallback cost, and the times its latency
 * is made of. Lives in one request; what other requests must know is on the Card.
 */
final class Run
{
    public string $text = '';

    /** @var array{input: int, cached: int, written: int, output: int}|null */
    public ?array $usage = null;

    public SkipGate $gate;

    /** This answer is held until complete (`hold_until_complete`): a refusal after words is never shown. */
    public bool $hold = false;

    public bool $fallbackUsed = false;

    public float $requestedAt;

    public ?float $firstTokenAt = null;

    public ?float $lastTokenAt = null;

    public ?float $firstSentAt = null;

    public float $progressAt = 0.0;

    /** When the card was last looked at to see whether this suggestion is still the one being written. */
    public float $checkedAt = 0.0;

    /** @var array{tokens: array{input: int, cached: int, written: int, output: int}, micros: int}|null attempts given up for the fallback model */
    public ?array $abandoned = null;

    /**
     * @param  array{id: string, model: string, cents: int, reservation: AssistUsage|null, max: int, prompt: Prompt}|null  $upgrade  Balanced's upgrade for a hard question
     */
    public function __construct(
        public string $id,
        public readonly Origin $origin,
        public readonly string $mode,
        public string $model,
        public ?string $fallback,
        public readonly Prompt $prompt,
        public readonly int $max,
        public ?AssistUsage $reservation,
        public readonly float $t0,
        public ?string $replaces = null,
        public ?array $upgrade = null,
    ) {
        $this->gate = new SkipGate;
        $this->requestedAt = microtime(true);
    }

    /** Starts over on another model: the words and counts so far belong to the attempt given up. */
    public function switchTo(string $model): void
    {
        $this->model = $model;
        $this->fallback = null;
        $this->fallbackUsed = true;
        $this->text = '';
        $this->usage = null;
        $this->gate = new SkipGate;
        $this->hold = false;
        $this->firstTokenAt = $this->lastTokenAt = null;
    }

    /**
     * The counts of the attempt in hand: the provider's, or when it sent none (cut short) the estimate: the prompt as
     * input, the words so far as output.
     *
     * @return array{0: array{input: int, cached: int, written: int, output: int}, 1: bool} the counts and whether they are an estimate
     */
    public function tokens(): array
    {
        if ($this->usage !== null) {
            return [$this->usage, false];
        }

        return [['input' => $this->prompt->tokens(), 'cached' => 0, 'written' => 0, 'output' => Pricing::estimateTokens($this->text)], true];
    }
}
