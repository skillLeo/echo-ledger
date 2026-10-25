<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Http\Controllers\Controller;
use App\Services\Usage\UsageSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * POST /api/usage — idempotent streaming usage report (docs/API.md).
 *
 * The request is validated and echoed back; storing it in the usage ledger follows with the ledger itself.
 */
final class UsageController extends Controller
{
    public function store(Request $request, UsageSummary $usage): JsonResponse
    {
        $data = $request->validate([
            'report_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,64}$/'],
            'use_case' => ['required', 'string', Rule::in([SttUseCase::LiveCaptions->value, SttUseCase::Dictation->value])],
            'provider' => ['required', 'string', Rule::enum(SttProviderKey::class)],
            'session_id' => ['nullable', 'string', 'size:26'],
            'streamed_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'channels' => ['required', 'integer', 'min:1', 'max:2'],
            'ended_at' => ['nullable', ...SessionController::instantRules()],
        ]);

        $streamed = (int) $data['streamed_ms'];
        $channels = (int) $data['channels'];

        return response()->json([
            'report' => [
                'id' => null,
                'report_id' => $data['report_id'],
                'use_case' => $data['use_case'],
                'provider' => $data['provider'],
                'session_id' => $data['session_id'] ?? null,
                'streamed_ms' => $streamed,
                'channels' => $channels,
                'billed_ms' => $streamed * $channels,
                'cost_usd' => 0.0,
                'occurred_at' => (isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : now())->utc()->toAtomString(),
                'duplicate' => false,
            ],
            'usage' => $usage->toArray(),
        ], 201);
    }
}
