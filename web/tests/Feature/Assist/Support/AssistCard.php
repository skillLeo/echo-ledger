<?php

declare(strict_types=1);

namespace Tests\Feature\Assist\Support;

/**
 * The Live Assist card as the apps keep it (desktop/src/v2/assist/card.ts, the phone's AssistModel): a pure reducer of
 * the relay's messages, here in PHP so that the server's tests can play the shared sequences
 * (docs/v2/assist-card-sequences.json) through both transports. Only what the sequences check is kept: the suggestions,
 * the one on the card, and "No reply needed".
 *
 * A state is `['suggestions' => list<array>, 'shownId' => string|null, 'skipped' => bool]`; a suggestion is
 * `['id', 'replaces', 'text', 'status']` with `status` streaming, done, cancelled, superseded, failed or skipped.
 */
final class AssistCard
{
    /** @return array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool} */
    public static function empty(): array
    {
        return ['suggestions' => [], 'shownId' => null, 'skipped' => false];
    }

    /**
     * @param  array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool}  $state
     * @param  array<string, mixed>  $m
     * @return array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool}
     */
    public static function reduce(array $state, array $m): array
    {
        switch ($m['type'] ?? null) {
            case 'ready':
                // Resumed: a suggestion that was streaming when the connection dropped gets no end; it leaves the card.
                if (! ($m['resumed'] ?? false)) {
                    return $state;
                }
                $shownGone = (self::find($state, $state['shownId'])['status'] ?? null) === 'streaming';
                $state['suggestions'] = array_map(fn (array $s): array => $s['status'] === 'streaming' ? [...$s, 'text' => '', 'status' => 'cancelled'] : $s, $state['suggestions']);
                $state['shownId'] = $shownGone ? null : $state['shownId'];

                return $state;
            case 'suggestion_start':
                $current = self::find($state, $state['shownId']);
                // A replacement waits off the card while the one it replaces still has words on it.
                $waits = ($m['replaces'] ?? null) !== null && $m['replaces'] === $state['shownId'] && $current !== null && $current['text'] !== '';
                $new = ['id' => $m['suggestion_id'], 'replaces' => $m['replaces'] ?? null, 'text' => '', 'status' => 'streaming'];

                return ['suggestions' => [...array_slice($state['suggestions'], -19), $new], 'shownId' => $waits ? $state['shownId'] : $m['suggestion_id'], 'skipped' => false];
            case 'token':
                $s = self::find($state, $m['suggestion_id']);
                if ($s === null || $s['status'] !== 'streaming') {
                    return $state;
                }
                $next = self::patch($state, $m['suggestion_id'], fn (array $x): array => [...$x, 'text' => $x['text'].$m['text']]);
                // Its first token takes the card from the suggestion it replaces.
                if ($s['replaces'] !== null && $state['shownId'] === $s['replaces'] && $s['text'] === '') {
                    $next['shownId'] = $s['id'];
                }

                return $next;
            case 'suggestion_end':
                $id = $m['suggestion_id'];
                if ($m['status'] === 'superseded') {
                    $next = self::patch($state, $id, fn (array $x): array => [...$x, 'text' => '', 'status' => 'superseded']);
                    if ($state['shownId'] !== $id) {
                        return $next;
                    }
                    // Its replacement, if one has started, takes the card at once (still writing: never a blank card).
                    foreach (array_reverse($state['suggestions']) as $x) {
                        if ($x['replaces'] === $id) {
                            return [...$next, 'shownId' => $x['id']];
                        }
                    }

                    return $next;
                }
                if ($m['status'] === 'cancelled') {
                    $next = self::patch($state, $id, fn (array $x): array => [...$x, 'status' => 'cancelled']);

                    return $state['shownId'] === $id ? [...$next, 'shownId' => self::previousShown($state, $id)] : $next;
                }

                return self::patch($state, $id, fn (array $x): array => [...$x, 'status' => 'done']);
            case 'skip':
                // No reply needed: the suggestion it names ends without words.
                if (($m['suggestion_id'] ?? null) === null) {
                    return [...$state, 'skipped' => true];
                }
                $next = self::patch($state, $m['suggestion_id'], fn (array $x): array => [...$x, 'text' => '', 'status' => 'skipped']);

                return [...$next, 'skipped' => true, 'shownId' => $state['shownId'] === $m['suggestion_id'] ? null : $state['shownId']];
            case 'error':
                return ($m['suggestion_id'] ?? null) !== null ? self::patch($state, $m['suggestion_id'], fn (array $x): array => [...$x, 'text' => '', 'status' => 'failed']) : $state;
            default:
                // `mode_changed`, `end` and the rest do not touch the card.
                return $state;
        }
    }

    /**
     * The card as docs/v2/assist-card-sequences.json describes it, with [$also] the suggestions it asks about by id.
     *
     * @param  array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool}  $state
     * @param  list<array{id: string}>|null  $also
     * @return array<string, mixed>
     */
    public static function cardOf(array $state, ?array $also = null): array
    {
        $shown = self::find($state, $state['shownId']);
        $card = ['shown_id' => $state['shownId'], 'text' => $shown['text'] ?? '', 'status' => $shown['status'] ?? null, 'skipped' => $state['skipped']];
        if ($also !== null) {
            $card['also'] = array_map(function (array $want) use ($state): array {
                $x = self::find($state, $want['id']);

                return ['id' => $want['id'], 'text' => $x['text'] ?? "(not on the card's list)", 'status' => $x['status'] ?? '(none)'];
            }, $also);
        }

        return $card;
    }

    /**
     * @param  array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool}  $state
     * @return array<string, mixed>|null
     */
    private static function find(array $state, ?string $id): ?array
    {
        foreach ($state['suggestions'] as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    /**
     * @param  array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool}  $state
     * @return array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool}
     */
    private static function patch(array $state, string $id, \Closure $change): array
    {
        $state['suggestions'] = array_map(fn (array $s): array => $s['id'] === $id ? $change($s) : $s, $state['suggestions']);

        return $state;
    }

    /**
     * The suggestion the card falls back to when [$id] goes without words: the one shown before it, if it still has text.
     *
     * @param  array{suggestions: list<array<string, mixed>>, shownId: string|null, skipped: bool}  $state
     */
    private static function previousShown(array $state, string $id): ?string
    {
        $index = array_search($id, array_column($state['suggestions'], 'id'), true);
        for ($j = (int) $index - 1; $j >= 0 && $index !== false; $j--) {
            $s = $state['suggestions'][$j];
            if ($s['text'] !== '' && in_array($s['status'], ['done', 'streaming'], true)) {
                return $s['id'];
            }
        }

        return null;
    }
}
