<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\LiveTranslate\LiveTranslateBudget;
use App\Services\Usage\UsageLedger;
use App\Services\Usage\UsageSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/usage — idempotent streaming usage report (docs/API.md). Accepted even while the monthly limit is
 * reached: the spend already happened and the ledger must know about it.
 */
final class UsageController extends Controller
{
    public function store(Request $request, UsageLedger $ledger, UsageSummary $usage): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'report_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,64}$/'],
            'use_case' => ['required', 'string', Rule::in([
                SttUseCase::LiveCaptions->value, SttUseCase::Dictation->value, SttUseCase::GameTranslate->value, SttUseCase::WatchTranslate->value,
            ])],
            // Live Translate is always Soniox (POST /api/live-translate/token).
            'provider' => ['required', 'string', Rule::enum(SttProviderKey::class),
                Rule::when(in_array($request->input('use_case'), LiveTranslateBudget::useCases(), true), [Rule::in([SttProviderKey::Soniox->value])])],
            'session_id' => ['nullable', 'string', 'size:26'],
            'streamed_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'channels' => ['required', 'integer', 'min:1', 'max:2'],
            'ended_at' => ['nullable', ...SessionController::instantRules()],
        ]);

        ['entry' => $entry, 'duplicate' => $duplicate] = $ledger->recordStreaming($device, $data);

        return response()->json([
            'report' => [
                'id' => $entry->id,
                'report_id' => $entry->report_id,
                'use_case' => $entry->use_case,
                'provider' => $entry->provider,
                'session_id' => $entry->capture_session_id,
                'streamed_ms' => $entry->duration_ms,
                'channels' => $entry->channels,
                'billed_ms' => $entry->billed_ms,
                'cost_usd' => round((float) $entry->cost_usd, 4),
                'occurred_at' => $entry->occurred_at->utc()->toAtomString(),
                'duplicate' => $duplicate,
            ],
            'usage' => $usage->toArray($device->workspace_id),
            // Since v1.3: Live Translate's own budget, so the phone can warn at 80 % and pause at 100 %.
            'live_translate' => app(LiveTranslateBudget::class)->state($device->workspace_id),
        ], $duplicate ? 200 : 201);
    }
}
