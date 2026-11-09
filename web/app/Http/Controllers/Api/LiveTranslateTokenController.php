<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\CredentialVault;
use App\Services\LiveTranslate\LiveTranslateBudget;
use App\Services\SettingsRepository;
use App\Services\Usage\StreamingGrants;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * POST /api/live-translate/token (since v1.3, Phase B): a short-lived Soniox key for Live Translate on the phone (game
 * voice chat and videos, translated as they are heard). The phone opens Soniox's real-time WebSocket itself with it;
 * no audio passes through this server and the account key never leaves it. The phone asks again before the key
 * expires.
 *
 * Live Translate has its own monthly budget (LiveTranslateBudget, apart from the transcription limit): at 100 % no key
 * is given, unless an owner or admin of the workspace chose "Continue this month anyway" on the phone
 * (`continue_over_budget`), and past twice the budget to nobody.
 */
final class LiveTranslateTokenController extends Controller
{
    public const EXPIRES_IN_SECONDS = 600;

    /** "Continue this month anyway" goes up to this many times the budget. */
    public const OVER_BUDGET_CAP = 2;

    public const WEBSOCKET_URL = 'wss://stt-rt.soniox.com/transcribe-websocket';

    private const TEMPORARY_KEY_URL = 'https://api.soniox.com/v1/auth/temporary-api-key';

    public function __invoke(Request $request, CredentialVault $vault, SettingsRepository $settings, LiveTranslateBudget $budget, StreamingGrants $grants): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'mode' => ['required', Rule::in(['game', 'watch'])],
            'target_language' => ['required', 'string', 'regex:/^[a-z]{2}$/'],
            'language_hints' => ['nullable', 'array', 'max:4'],
            'language_hints.*' => ['string', 'regex:/^[a-z]{2}$/'],
            'continue_over_budget' => ['sometimes', 'boolean'],
        ]);
        app(PlanLimits::class)->assertFeature($device->workspace_id, 'live_translate', $device->user_id);
        $spending = $budget->state($device->workspace_id);
        if ($spending['reached']) {
            $this->assertMayContinue($device, (float) $spending['used_usd'], (float) $spending['limit_usd'], $request->boolean('continue_over_budget'));
        }
        $key = $vault->get('soniox', 'api_key');
        if ($key === null || $key === '') {
            throw new ApiError(503, 'provider_not_configured', 'Live Translate needs a Soniox key. Add it in API Credentials, then try again.');
        }
        try {
            $answer = Http::withToken($key)->acceptJson()->connectTimeout(10)->timeout(15)
                ->post(self::TEMPORARY_KEY_URL, ['usage_type' => 'transcribe_websocket', 'expires_in_seconds' => self::EXPIRES_IN_SECONDS])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw new ApiError(502, 'provider_error', match ($e->response->status()) {
                401, 403 => 'Soniox did not accept the key in API Credentials. Check it there, then try again.',
                402 => 'Soniox has no credit left. Add credit to the Soniox account, then try again.',
                default => 'Soniox could not start Live Translate right now. Try again in a minute.',
            });
        } catch (ConnectionException) {
            throw new ApiError(502, 'provider_error', "Soniox could not be reached. Check the server's internet connection, then try again.");
        }
        $temporary = is_array($answer) ? ($answer['api_key'] ?? null) : null;
        if (! is_string($temporary) || $temporary === '') {
            throw new ApiError(502, 'provider_error', 'Soniox answered without a key. Try again in a minute.');
        }

        // What this device reports with POST /api/usage counts from here on.
        $grants->note($device);

        return response()->json([
            'provider' => 'soniox',
            'api_key' => $temporary,
            'expires_at' => is_string($answer['expires_at'] ?? null) ? $answer['expires_at'] : now()->addSeconds(self::EXPIRES_IN_SECONDS)->toAtomString(),
            'websocket_url' => self::WEBSOCKET_URL,
            'model' => (string) $settings->get('live_translate.model', 'stt-rt-v5'),
            'translation' => ['type' => 'one_way', 'target_language' => $data['target_language']],
            'language_hints' => array_values(array_unique($data['language_hints'] ?? [])),
            'budget' => $spending,
        ])->header('Cache-Control', 'no-store');
    }

    /** The budget is used up: `403 budget_exceeded`, unless someone who may spend more asked to, and it is not spent twice over. */
    private function assertMayContinue(Device $device, float $used, float $limit, bool $asked): void
    {
        $person = $device->user;
        $mayDecide = $person->is_super_admin || app(Workspaces::class)->roleOf($person, $device->workspace_id)?->manages() === true;
        $capped = $used >= $limit * self::OVER_BUDGET_CAP;
        if ($asked && $mayDecide && ! $capped) {
            return;
        }
        throw new ApiError(403, 'budget_exceeded', match (true) {
            ! $mayDecide => sprintf("Live Translate's budget of $%.2f for this month is used up. Ask an owner of this workspace.", $limit),
            $capped => sprintf('Live Translate has used twice its budget of $%.2f this month. Raise it in Settings → Game translate.', $limit),
            default => sprintf("Live Translate's budget of $%.2f for this month is used up. Raise it in Settings → Game translate, or continue this month anyway.", $limit),
        });
    }
}
