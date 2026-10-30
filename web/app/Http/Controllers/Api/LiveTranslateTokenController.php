<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\CredentialVault;
use App\Services\LiveTranslate\LiveTranslateBudget;
use App\Services\SettingsRepository;
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
 * is given unless the person chose "Continue this month anyway" on the phone (`continue_over_budget`).
 */
final class LiveTranslateTokenController extends Controller
{
    public const EXPIRES_IN_SECONDS = 600;

    public const WEBSOCKET_URL = 'wss://stt-rt.soniox.com/transcribe-websocket';

    private const TEMPORARY_KEY_URL = 'https://api.soniox.com/v1/auth/temporary-api-key';

    public function __invoke(Request $request, CredentialVault $vault, SettingsRepository $settings, LiveTranslateBudget $budget): JsonResponse
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
        $spending = $budget->state($device->workspace_id);
        if ($spending['reached'] && ! $request->boolean('continue_over_budget')) {
            throw new ApiError(403, 'budget_exceeded', sprintf(
                "Live Translate's budget of $%.2f for this month is used up. Raise it in Settings → Game translate, or continue this month anyway.",
                (float) $spending['limit_usd'],
            ));
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
}
