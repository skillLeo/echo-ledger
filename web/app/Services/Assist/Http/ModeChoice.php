<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use App\Services\Assist\AssistPlans;

/**
 * The mode a session in HTTP mode runs now, as the relay chooses it (services/assist-relay/src/budget.rs choose_mode):
 * the most capable mode at or below the one asked for that the plan allows and the budget left still pays for, or
 * `captions_only` when none does (captions never stop).
 */
class ModeChoice
{
    public function __construct(private readonly AssistPlans $plans, private readonly Pricing $pricing) {}

    public function effective(AssistSession $session, ?int $contextTokens = null): string
    {
        $left = $this->plans->left($session->workspace_id, $session->user_id, $session->practice);
        if ($left['seconds_left'] <= 0 || $left['cents_left'] <= 0) {
            return 'captions_only';
        }
        $allowed = $this->plans->modes($session->workspace_id, $session->practice);
        $order = array_values((array) config('assist.modes_order', ['max', 'deep', 'balanced', 'fast']));
        $requested = array_search($session->mode, $order, true);
        $micros = $left['cents_left'] * 10_000;
        $reserve = (int) config('assist.http.reserve_suggestions', 3);
        foreach ($order as $rank => $mode) {
            if (($requested !== false && $rank < $requested) || ! in_array($mode, $allowed, true)) {
                continue;
            }
            // A mode needs room for its usual reserve of suggestions (one in Fast) and for one at its worst case.
            $need = max($this->pricing->modeEstimate($mode, $contextTokens) * ($mode === 'fast' ? 1 : $reserve), $this->pricing->modeWorst($mode, $contextTokens));
            if ($need <= $micros) {
                return $mode;
            }
        }

        return 'captions_only';
    }
}
