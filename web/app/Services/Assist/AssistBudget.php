<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Http\Controllers\Api\Assist\InternalAssistController;
use App\Models\AssistSession;
use App\Models\AssistUsage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The server's own model calls for Live Assist (the report, Ask) reserve their worst case before they are sent, so
 * several at once can never spend more than is left (v1.9 L7, the security review of Ask):
 *
 * - reserve(): with the person's row locked, checks what is left of the session's allowance (AssistPlans::left, which
 *   counts every usage row, the pending ones too) and, when the worst case fits, writes a pending usage row for it;
 * - settle(): the row takes the call's real cost (or stays at the worst case, estimated, when the answer was lost
 *   after sending) and goes into the month's usage ledger;
 * - release(): a call that was never sent (or never billed) gives its reservation back.
 *
 * A reservation whose process died stays at its worst case: never less than was spent.
 */
final class AssistBudget
{
    public const PENDING = 'pending';

    public function __construct(private readonly AssistPlans $plans) {}

    /**
     * Reserves [$cents] (the call's worst case) for a [$kind] call of [$session], as usage row [$id]; null when it does
     * not fit what is left.
     */
    public function reserve(AssistSession $session, string $kind, string $model, int $cents, string $id): ?AssistUsage
    {
        return DB::transaction(function () use ($session, $kind, $model, $cents, $id): ?AssistUsage {
            // One person's reservations one at a time, whichever device or job asks.
            User::query()->whereKey($session->user_id)->lockForUpdate()->first();
            if ($cents > $this->plans->left($session->workspace_id, $session->user_id, $session->practice)['cents_left']) {
                return null;
            }

            return AssistUsage::query()->create([
                'assist_session_id' => $session->id, 'workspace_id' => $session->workspace_id, 'user_id' => $session->user_id,
                'suggestion_id' => $id, 'provider' => (string) config("assist.models.{$model}.provider", 'anthropic'), 'model' => $model,
                'mode' => $session->mode, 'plan' => $session->plan ?? $this->plans->for($session->workspace_id)['key'],
                'kind' => $kind, 'trigger' => $kind, 'outcome' => self::PENDING, 'estimated' => true,
                'cost_usd' => round($cents / 100, 6), 'occurred_at' => now(),
            ]);
        });
    }

    /**
     * The reservation takes the call's real tokens and cost (the catalog's [$catalogModel] prices), or the worst case
     * when [$lost], and is counted in the month's usage ledger.
     *
     * @param  array{input: int, cached: int, written: int, output: int}  $tokens
     */
    public function settle(AssistUsage $reservation, string $catalogModel, string $model, array $tokens, bool $lost = false): void
    {
        $cost = $lost ? (float) $reservation->cost_usd : AssistReport::costUsd($catalogModel, $tokens);
        DB::transaction(function () use ($reservation, $model, $tokens, $cost, $lost): void {
            $reservation->forceFill([
                'model' => $model, 'outcome' => $lost ? 'lost' : 'done', 'estimated' => $lost,
                'input_tokens' => $tokens['input'], 'cached_tokens' => $tokens['cached'], 'written_tokens' => $tokens['written'], 'output_tokens' => $tokens['output'],
                'cost_usd' => round($cost, 6),
            ])->save();
            $session = AssistSession::query()->withoutGlobalScopes()->withTrashed()->findOrFail($reservation->assist_session_id);
            InternalAssistController::ledger($session, [
                'suggestion_id' => $reservation->suggestion_id, 'provider' => $reservation->provider, 'model' => $model,
                'tokens' => $tokens, 'cost_usd' => $cost,
            ], Carbon::parse($reservation->occurred_at));
        });
    }

    /** Gives back a reservation whose call was never sent, or never billed. */
    public function release(AssistUsage $reservation): void
    {
        $reservation->delete();
    }
}
