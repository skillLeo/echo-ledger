<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Assist;

use App\Http\Controllers\Controller;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistUsage;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The internal Live Assist API, relay → server (docs/API_ASSIST.md "Internal"), behind VerifyAssistRelay. What the relay
 * sends is in services/assist-relay/src/internal.rs. A session the server does not know answers 404, which the relay
 * does not try again.
 */
final class InternalAssistController extends Controller
{
    /** GET /api/internal/assist/providers: the provider keys (memory only on the relay), the models and the mode map. */
    public function providers(CredentialVault $vault): JsonResponse
    {
        $keys = [];
        foreach ((array) config('assist.relay_providers', []) as $provider) {
            $key = $vault->get((string) $provider, 'api_key');
            if ($key !== null) {
                $keys[(string) $provider] = $key;
            }
        }
        $models = [];
        foreach ((array) config('assist.models', []) as $id => $model) {
            // The relay reads each model strictly: optional parts are left out rather than sent empty.
            $models[(string) $id] = array_filter((array) $model, fn (mixed $v): bool => $v !== null && $v !== []);
        }
        $modes = [];
        foreach ((array) config('assist.modes', []) as $mode => $plan) {
            $modes[(string) $mode] = array_filter((array) $plan, fn (mixed $v): bool => $v !== null);
        }

        return response()->json(['keys' => (object) $keys, 'models' => $models, 'modes' => $modes])->header('Cache-Control', 'no-store');
    }

    /** POST /api/internal/assist/usage: one suggestion's tokens and cost; the same suggestion_id once only. */
    public function usage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'suggestion_id' => ['required', 'string', 'max:128'],
            'session_id' => ['required', 'string', 'max:26'],
            'model' => ['required', 'string', 'max:128'],
            'provider' => ['required', 'string', 'max:20'],
            'mode' => ['required', 'string', 'max:10'],
            'kind' => ['required', 'string', 'max:20'],
            'trigger' => ['sometimes', 'nullable', 'string', 'max:20'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:20'],
            'fallback' => ['sometimes', 'boolean'],
            'tokens' => ['required', 'array'],
            'tokens.input' => ['required', 'integer', 'min:0'],
            'tokens.cached' => ['required', 'integer', 'min:0'],
            'tokens.written' => ['required', 'integer', 'min:0'],
            'tokens.output' => ['required', 'integer', 'min:0'],
            'cost_usd' => ['required', 'numeric', 'min:0', 'max:1000'],
            'estimated' => ['sometimes', 'boolean'],
            'latency' => ['sometimes', 'nullable', 'array'],
            'at' => ['sometimes', 'nullable', 'date'],
        ]);
        $session = $this->session((string) $data['session_id']);
        if (AssistUsage::query()->where('suggestion_id', $data['suggestion_id'])->exists()) {
            return response()->json(['stored' => false]);
        }
        $at = isset($data['at']) ? Carbon::parse((string) $data['at'])->utc() : now();
        DB::transaction(function () use ($data, $session, $at): void {
            AssistUsage::query()->create([
                'assist_session_id' => $session->id,
                'workspace_id' => $session->workspace_id,
                'user_id' => $session->user_id,
                'suggestion_id' => $data['suggestion_id'],
                'provider' => $data['provider'],
                'model' => $data['model'],
                'mode' => $data['mode'],
                'kind' => $data['kind'],
                'trigger' => $data['trigger'] ?? null,
                'outcome' => $data['outcome'] ?? null,
                'fallback' => (bool) ($data['fallback'] ?? false),
                'input_tokens' => (int) $data['tokens']['input'],
                'cached_tokens' => (int) $data['tokens']['cached'],
                'written_tokens' => (int) $data['tokens']['written'],
                'output_tokens' => (int) $data['tokens']['output'],
                'cost_usd' => round((float) $data['cost_usd'], 6),
                'estimated' => (bool) ($data['estimated'] ?? false),
                'latency' => is_array($data['latency'] ?? null) ? array_filter($data['latency'], 'is_numeric') : null,
                'occurred_at' => $at,
            ]);
            // The month's totals (Usage pages, the CSV) count it with every other use.
            UsageLedgerEntry::query()->create([
                'workspace_id' => $session->workspace_id,
                'user_id' => $session->user_id,
                'device_id' => $session->device_id,
                'capture_session_id' => $session->capture_session_id,
                'report_id' => 'assist/'.$data['suggestion_id'],
                'source' => UsageLedgerEntry::SOURCE_SERVER,
                'use_case' => 'live_assist',
                'provider' => (string) $data['provider'],
                'model' => mb_substr((string) $data['model'], 0, 64),
                'duration_ms' => 0,
                'channels' => 1,
                'billed_ms' => 0,
                'input_tokens' => (int) $data['tokens']['input'] + (int) $data['tokens']['cached'] + (int) $data['tokens']['written'],
                'output_tokens' => (int) $data['tokens']['output'],
                'unit_price_usd' => 0,
                'cost_usd' => round((float) $data['cost_usd'], 6),
                'billable' => true,
                'occurred_at' => $at,
            ]);
        });

        return response()->json(['stored' => true]);
    }

    /** POST /api/internal/assist/suggestions: the words of a suggestion that was shown, for the report. */
    public function suggestions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'suggestion_id' => ['required', 'string', 'max:128'],
            'session_id' => ['required', 'string', 'max:26'],
            'turn_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:20'],
            'mode' => ['sometimes', 'nullable', 'string', 'max:10'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'replaces' => ['sometimes', 'nullable', 'string', 'max:128'],
            'text' => ['required', 'string', 'max:20000'],
            'at' => ['sometimes', 'nullable', 'date'],
        ]);
        $session = $this->session((string) $data['session_id']);
        AssistSuggestion::query()->updateOrCreate(
            ['assist_session_id' => $session->id, 'suggestion_id' => $data['suggestion_id']],
            [
                'turn_id' => $data['turn_id'] ?? null, 'kind' => $data['kind'] ?? null, 'mode' => $data['mode'] ?? null,
                'model' => $data['model'] ?? null, 'replaces' => $data['replaces'] ?? null, 'text' => $data['text'],
                'shown_at' => isset($data['at']) ? Carbon::parse((string) $data['at'])->utc() : now(),
            ],
        );

        return response()->json(['stored' => true]);
    }

    /** POST /api/internal/assist/facts: the facts ledger as it is now (it replaces the last one). */
    public function facts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:26'],
            'facts' => ['present', 'array', 'max:500'],
            'facts.*' => ['required'],
        ]);
        $facts = array_values(array_filter($data['facts'], fn (mixed $f): bool => (is_string($f) && mb_strlen($f) <= 2000) || is_array($f)));
        $this->session((string) $data['session_id'])->forceFill(['facts' => $facts])->save();

        return response()->json(['stored' => true]);
    }

    /** POST /api/internal/assist/summary: the running summary as it is now. */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string', 'max:26'], 'summary' => ['present', 'nullable', 'string', 'max:20000']]);
        $this->session((string) $data['session_id'])->forceFill(['summary' => $data['summary']])->save();

        return response()->json(['stored' => true]);
    }

    private function session(string $id): AssistSession
    {
        $session = AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->find($id);
        if (! $session instanceof AssistSession) {
            throw new NotFoundHttpException;
        }

        return $session;
    }
}
