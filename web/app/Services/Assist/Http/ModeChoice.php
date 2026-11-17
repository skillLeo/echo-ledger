<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use App\Services\Assist\AssistPlans;

/**
 * The mode a session in HTTP mode runs now, as the relay chooses it (services/assist-relay/src/budget.rs choose_mode):
 * the most capable allowed mode at or below the one asked for that the budget left still pays for, keeping room for
 * its usual reserve of suggestions (one in Fast) and for one suggestion at its worst case; `captions_only` when none
 * does (captions never stop). It follows what is spent, not what calls in flight have reserved, so the mode never moves
 * for a call in flight. `evaluate()` tells the app when it changes, as the relay's `mode_changed`.
 */
class ModeChoice
{
    public const CAUSE_REQUESTED = 'requested';

    public const CAUSE_PLAN = 'plan';

    public const CAUSE_BUDGET = 'budget';

    public const CAUSE_TIME = 'time';

    /** Mode ranks for "rising": captions only, then Fast up to Max. */
    private const RANK = ['captions_only' => 0, 'fast' => 1, 'balanced' => 2, 'deep' => 3, 'max' => 4];

    public function __construct(
        private readonly AssistPlans $plans,
        private readonly Pricing $pricing,
        private readonly LiveContext $context,
        private readonly LiveState $state,
    ) {}

    public function effective(AssistSession $session, ?int $contextTokens = null): string
    {
        return $this->choose($session, $this->requested($session), $contextTokens)['mode'];
    }

    /** The mode Me asked for: the last `settings.mode` of the call's context, else the one the session started with. */
    public function requested(AssistSession $session): string
    {
        $asked = $this->context->get($session)['settings']['mode'] ?? null;

        return is_string($asked) && in_array($asked, (array) config('assist.modes_order'), true) ? $asked : $session->mode;
    }

    /**
     * @return array{mode: string, cause: string|null} the mode to run and why it is not the requested one (null: none)
     */
    public function choose(AssistSession $session, string $requested, ?int $contextTokens = null): array
    {
        $left = $this->plans->left($session->workspace_id, $session->user_id, $session->practice, withPending: false);
        if ($left['seconds_left'] <= 0) {
            return ['mode' => 'captions_only', 'cause' => self::CAUSE_TIME];
        }
        $order = array_values((array) config('assist.modes_order', ['max', 'deep', 'balanced', 'fast']));
        $allowed = array_values(array_filter($order, fn (string $m): bool => in_array($m, $this->plans->modes($session->workspace_id, $session->practice), true)));
        $rank = fn (string $mode): int => (int) array_search($mode, $order, true);
        // Capped by the request; when the plan allows only modes above it, the cheapest of those.
        $candidates = array_values(array_filter($allowed, fn (string $m): bool => $rank($m) >= $rank($requested)));
        if ($candidates === [] && $allowed !== []) {
            $candidates = [end($allowed)];
        }
        if ($candidates === []) {
            return ['mode' => 'captions_only', 'cause' => self::CAUSE_PLAN];
        }
        $capped = $candidates[0];
        $micros = $left['cents_left'] * 10_000;
        $reserve = (int) config('assist.http.reserve_suggestions', 3);
        foreach ($candidates as $mode) {
            // A mode needs room for its usual reserve of suggestions (one in Fast) and for one at its worst case.
            $need = max($this->pricing->modeEstimate($mode, $contextTokens) * ($mode === 'fast' ? 1 : $reserve), $this->pricing->modeWorst($mode, $contextTokens));
            if ($need <= $micros) {
                return ['mode' => $mode, 'cause' => match (true) {
                    $mode !== $capped => self::CAUSE_BUDGET,
                    $capped === $requested => self::CAUSE_REQUESTED,
                    default => self::CAUSE_PLAN,
                }];
            }
        }

        return ['mode' => 'captions_only', 'cause' => self::CAUSE_BUDGET];
    }

    /** Remembers [$mode] as the one the app was last told (the start and refresh answers carry it in `ready`). */
    public function announce(AssistSession $session, string $mode): void
    {
        $this->state->setMode($session, $mode, false);
    }

    /**
     * Looks at the mode again and, when it moved since the app was last told, sends `mode_changed` on [$stream]. [$why]
     * is `settings` (the app asked), `request` (a suggestion is starting) or `spend` (a call was charged).
     *
     * @return string the effective mode now
     */
    public function evaluate(AssistSession $session, EventStream $stream, string $why): string
    {
        $next = $this->choose($session, $this->requested($session));
        $known = $this->state->mode($session);
        $previous = $known['mode'] ?? null;
        if ($previous === null) {
            // Nothing announced yet (a session from before the first `ready` was kept): this is the starting point.
            $this->state->setMode($session, $next['mode']);

            return $next['mode'];
        }
        if ($previous === $next['mode']) {
            return $next['mode'];
        }
        $rising = self::RANK[$next['mode']] > self::RANK[$previous];
        $reason = match (true) {
            $next['cause'] === self::CAUSE_TIME => 'assist_time',
            $next['cause'] === self::CAUSE_BUDGET => 'budget',
            $next['cause'] === self::CAUSE_PLAN => 'plan',
            $why === 'settings' => 'requested',
            $rising => 'budget_restored',
            default => 'requested',
        };
        $this->state->setMode($session, $next['mode'], $reason === 'budget');
        $stream->send('mode_changed', ['from' => $previous, 'to' => $next['mode'], 'reason' => $reason]);

        return $next['mode'];
    }
}
