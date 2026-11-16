<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Http\Controllers\Api\LiveTranslateTokenController;
use App\Models\AssistSession;
use App\Services\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The captions block of a Live Assist session (docs/API_ASSIST.md `stt`): a short-lived streaming key, as Live
 * Translate gets one. Null when the speech provider is not set up or does not answer: the apps then keep their own
 * captions (POST /api/stt/token), and Live Assist still starts.
 */
final class AssistStt
{
    public function __construct(private readonly CredentialVault $vault) {}

    /** @return array{provider: string, ws_url: string, token: string, expires_at: string, translate_other: bool}|null */
    public function block(AssistSession $session, bool $translateOther): ?array
    {
        $key = $this->vault->get('soniox', 'api_key');
        if ($key === null) {
            return null;
        }
        $ttl = min(900, LiveTranslateTokenController::EXPIRES_IN_SECONDS);
        try {
            $answer = Http::withToken($key)->acceptJson()->connectTimeout(10)->timeout(15)
                ->post('https://api.soniox.com/v1/auth/temporary-api-key', ['usage_type' => 'transcribe_websocket', 'expires_in_seconds' => $ttl])
                ->throw()->json();
        } catch (RequestException|ConnectionException $e) {
            Log::warning('Live Assist: no streaming key for the captions', ['session' => $session->id, 'status' => $e instanceof RequestException ? $e->response->status() : null]);

            return null;
        }
        $token = is_array($answer) ? ($answer['api_key'] ?? null) : null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        return [
            'provider' => 'soniox',
            'ws_url' => LiveTranslateTokenController::WEBSOCKET_URL,
            'token' => $token,
            'expires_at' => is_string($answer['expires_at'] ?? null) ? $answer['expires_at'] : now()->addSeconds($ttl)->toAtomString(),
            'translate_other' => $translateOther,
        ];
    }
}
