<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Prompt;

use App\Services\Assist\Http\Pricing;

/**
 * The final turns of a call as the relay keeps them (services/assist-relay/src/context.rs `Transcript`): the newest
 * few word for word, the older ones in blocks of about `block_tokens` that never change once closed (so each stays in
 * the provider's cache), the oldest blocks dropped together past `max_transcript_tokens` (the running summary covers
 * them). HTTP mode has no memory between requests: the transcript is replayed from the stored turns, which gives the
 * same blocks every time.
 */
final class Transcript
{
    /** Turns already in blocks that are kept as turns too (for help now on a turn id, and the last thing each side said). */
    private const KEPT_BLOCK_TURNS = 48;

    /** @var list<array{id: string, channel: string, text: string, start_ms: int}> */
    private array $turns = [];

    private int $inBlocks = 0;

    /** @var list<array{text: string, tokens: int}> */
    private array $full = [];

    /** @var array{text: string, tokens: int} */
    private array $open = ['text' => '', 'tokens' => 0];

    private int $dropped = 0;

    public function __construct(private readonly int $blockTokens, private readonly int $recentTurns, private readonly int $maxTokens) {}

    public static function fromConfig(): self
    {
        $block = (int) config('assist.context.block_tokens', 1500);

        return new self($block, (int) config('assist.context.recent_turns', 8), max($block, (int) config('assist.context.max_transcript_tokens', 40000)));
    }

    /** @param  array{id: string, channel: string, text: string, start_ms: int}  $turn */
    public function push(array $turn): void
    {
        $this->turns[] = $turn;
        while (count($this->turns) - $this->inBlocks > $this->recentTurns) {
            $line = self::line($this->turns[$this->inBlocks]);
            if ($this->open['text'] !== '') {
                $this->open['text'] .= "\n";
            }
            $this->open['text'] .= $line;
            $this->open['tokens'] = Pricing::estimateTokens($this->open['text']);
            $this->inBlocks++;
            if ($this->open['tokens'] >= $this->blockTokens) {
                $this->full[] = $this->open;
                $this->open = ['text' => '', 'tokens' => 0];
                $this->dropOldestBlocks();
            }
        }
        if ($this->inBlocks > 2 * self::KEPT_BLOCK_TURNS) {
            $forget = $this->inBlocks - self::KEPT_BLOCK_TURNS;
            $this->turns = array_slice($this->turns, $forget);
            $this->inBlocks -= $forget;
        }
    }

    /** @param  array{id: string, channel: string, text: string, start_ms: int}  $turn */
    public static function line(array $turn): string
    {
        $who = $turn['channel'] === 'me' ? 'Me' : 'Them';
        $seconds = intdiv(max(0, $turn['start_ms']), 1000);

        return sprintf('[%02d:%02d] %s: %s', intdiv($seconds, 60), $seconds % 60, $who, $turn['text']);
    }

    /**
     * The newest turns, word for word.
     *
     * @return list<array{id: string, channel: string, text: string, start_ms: int}>
     */
    public function latest(): array
    {
        return array_slice($this->turns, $this->inBlocks);
    }

    /** @return array{id: string, channel: string, text: string, start_ms: int}|null */
    public function lastFrom(string $channel): ?array
    {
        foreach (array_reverse($this->turns) as $turn) {
            if ($turn['channel'] === $channel) {
                return $turn;
            }
        }

        return null;
    }

    /** @return array{id: string, channel: string, text: string, start_ms: int}|null */
    public function find(string $id): ?array
    {
        foreach (array_reverse($this->turns) as $turn) {
            if ($turn['id'] === $id) {
                return $turn;
            }
        }

        return null;
    }

    /** Tokens in the closed blocks (the cached part of the transcript). */
    public function fullBlockTokens(): int
    {
        return array_sum(array_column($this->full, 'tokens'));
    }

    /** The upper bound of the closed blocks' tokens. */
    public function upperFullBlockTokens(): int
    {
        return array_sum(array_map(fn (array $b): int => Pricing::upperTokens($b['text']), $this->full));
    }

    /** Tokens the transcript adds to a request in [$scope] (`recent` or `full`). */
    public function tokens(string $scope): int
    {
        $latest = array_sum(array_map(fn (array $t): int => Pricing::estimateTokens(self::line($t)) + 1, $this->latest()));

        return $scope === 'full' ? $latest + $this->fullBlockTokens() + $this->open['tokens'] : $latest;
    }

    /** An upper bound of what the transcript adds to a request in [$scope]. */
    public function upperTokens(string $scope): int
    {
        $latest = array_sum(array_map(fn (array $t): int => Pricing::upperTokens(self::line($t)) + 1, $this->latest()));

        return $scope === 'full' ? $latest + $this->upperFullBlockTokens() + Pricing::upperTokens($this->open['text']) : $latest;
    }

    /** Whether any turn has gone into a block. */
    public function hasOlder(): bool
    {
        return $this->inBlocks > 0;
    }

    /** How many full blocks were dropped from the front (the parts keep their numbers). */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * The blocks as parts of a request: [text, closed] in order, the open block last.
     *
     * @return list<array{text: string, closed: bool}>
     */
    public function blocks(): array
    {
        $first = $this->dropped + 1;
        $blocks = [];
        foreach ($this->full as $i => $block) {
            $blocks[] = ['text' => 'Part '.($first + $i)."\n".$block['text'], 'closed' => true];
        }
        if ($this->open['text'] !== '') {
            $blocks[] = ['text' => 'Part '.($first + count($this->full))."\n".$this->open['text'], 'closed' => false];
        }

        return $blocks;
    }

    private function dropOldestBlocks(): void
    {
        $total = $this->fullBlockTokens();
        if ($total <= $this->maxTokens) {
            return;
        }
        $target = intdiv($this->maxTokens, 4) * 3;
        $drop = 0;
        while ($total > $target && $drop + 1 < count($this->full)) {
            $total -= $this->full[$drop]['tokens'];
            $drop++;
        }
        $this->full = array_slice($this->full, $drop);
        $this->dropped += $drop;
    }
}
