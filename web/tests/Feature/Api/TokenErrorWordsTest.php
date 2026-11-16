<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Http\Controllers\Api\LiveTranslateTokenController;
use App\Services\CredentialVault;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| W-003 (v1.8.1): the token endpoints' errors never carry a credential's name, a key's name or a provider's. The
| live-captions token's 503 says the documented plain message (the missing credential goes to the server log); Live
| Translate's token errors say "the Live Translate service", except where the phone reads the provider's name.
*/

/** Credential, key and provider words. */
const TOKEN_ERROR_WORDS = '/credential|api_key|\b(?:AssemblyAI|ElevenLabs|Soniox|Groq|Gemini|Azure|Anthropic|Claude|Whisper)\b/i';

/** The phone's short reason for a Live Translate notice, as mobile/.../translate/TranslateBudget.kt reads it. */
function phoneShortReason(?string $notice): ?string
{
    $text = mb_strtolower((string) $notice);

    return match (true) {
        $notice === null => null,
        str_contains($text, 'credit') => 'Needs Soniox credit',
        str_contains($text, 'soniox key') || str_contains($text, 'did not accept the key') => 'Needs a Soniox key',
        str_contains($text, 'budget') => 'Budget used up',
        default => null,
    };
}

it('answers a live-captions token without a key in plain words, and logs which credential is missing', function () {
    Log::spy();
    $device = Fx::device();
    $open = Fx::session($device->user, $device, ['status' => SessionStatus::Recording, 'created_at' => now()->subMinute()]);

    $response = $this->api(Fx::token($device))->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $open->id])
        ->assertStatus(503)->assertJsonPath('code', 'provider_not_configured')
        ->assertJsonPath('message', TranscriptErrorCode::ProviderNotConfigured->message());

    expect(preg_match(TOKEN_ERROR_WORDS, (string) $response->getContent()))->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'not configured')
        && str_contains((string) $context['detail'], 'Missing credential assemblyai.api_key'));
});

it('names no provider in Live Translate\'s token errors, except the one the phone reads, and the phone still tells them apart', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $ask = fn () => $this->api($token)->postJson('/api/live-translate/token', ['mode' => 'watch', 'target_language' => 'ur']);

    // No key: the phone reads "Soniox key" here, so it stays.
    $none = $ask()->assertStatus(503)->assertJsonPath('code', 'provider_not_configured')->json('message');
    expect($none)->toBe(LiveTranslateTokenController::NEEDS_KEY)->and(phoneShortReason($none))->toBe('Needs a Soniox key');

    app(CredentialVault::class)->put('soniox', 'api_key', 'snx-account-key-1234567890');
    // What the provider answers this time.
    $reply = null;
    Http::fake(['api.soniox.com/*' => function (Request $request) use (&$reply) {
        return $reply($request);
    }]);
    $answers = [];
    foreach ([401 => 'Needs a Soniox key', 403 => 'Needs a Soniox key', 402 => 'Needs Soniox credit', 500 => null] as $status => $short) {
        $reply = fn () => Http::response(['error' => 'refused'], $status);
        $answers[$status] = $ask()->assertStatus(502)->assertJsonPath('code', 'provider_error')->json('message');
        expect(phoneShortReason($answers[$status]))->toBe($short, "HTTP {$status}");
    }
    $reply = Http::failedConnection();
    $answers['unreachable'] = $ask()->assertStatus(502)->json('message');
    $reply = fn () => Http::response(['expires_at' => '2026-10-31T10:10:00Z']);
    $answers['no key'] = $ask()->assertStatus(502)->json('message');

    // No provider's name ("API Credentials" is where the person who runs the server checks the key).
    foreach ($answers as $case => $message) {
        expect(preg_match('/\b(?:Soniox|AssemblyAI|ElevenLabs|Groq|Gemini|Azure|Anthropic|Claude|Whisper)\b|api_key/i', (string) $message))->toBe(0, (string) $case);
    }
    expect($answers[402])->toBe('The Live Translate service has no credit left. Add credit to its account, then try again.')
        ->and(phoneShortReason($answers['unreachable']))->toBeNull()
        ->and(phoneShortReason($answers['no key']))->toBeNull();
});
