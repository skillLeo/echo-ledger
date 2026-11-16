<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Http\Controllers\Api\LiveTranslateTokenController;
use App\Http\Controllers\Api\SttTokenController;
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

/**
 * What the phone (v1.8.1, TranslateBudget.kt) makes of a refused Live Translate token: tokenProblem() turns the code and
 * the server's words into its own sentence, and shortReason() reads the line under Game and Watch from that.
 */
function phoneShortReasonV181(string $code, ?string $message): ?string
{
    $said = mb_strtolower((string) $message);
    $notice = match (true) {
        $code === 'budget_exceeded' => 'Live Translate\'s budget for this month is used up.',
        $code === 'provider_not_configured' => 'Live Translate is not set up on the server yet. Ask your admin.',
        $code === 'provider_error' && str_contains($said, 'credit') => 'Live Translate has no credit left on the server. Ask your admin to add credit.',
        $code === 'provider_error' && str_contains($said, 'key') => 'Live Translate is not set up on the server yet. Ask your admin.',
        $code === 'provider_error' => 'Live Translate\'s translation service is not answering. Try again later.',
        default => 'Live Translate could not start. Try again later.',
    };
    $text = mb_strtolower($notice);

    return match (true) {
        str_contains($text, 'credit') => 'Needs credit',
        str_contains($text, 'not set up') || str_contains($text, ' key') => 'Not set up on the server',
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
        ->assertJsonPath('message', SttTokenController::NOT_SET_UP);

    expect(preg_match(TOKEN_ERROR_WORDS, (string) $response->getContent()))->toBe(0)
        // A sentence for people, not the operator's "The speech provider is not configured."
        ->and($response->json('message'))->not->toContain('speech provider');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'not configured')
        && str_contains((string) $context['detail'], 'Missing credential assemblyai.api_key'));

    $dictation = $this->api(Fx::token($device))->postJson('/api/stt/token', ['use_case' => 'dictation'])->assertStatus(503)
        ->assertJsonPath('code', 'provider_not_configured');
    expect($dictation->json('message'))->toBe(SttTokenController::DICTATION_NOT_SET_UP)
        ->and(preg_match(TOKEN_ERROR_WORDS, (string) $dictation->getContent()))->toBe(0);
});

it('names no provider in Live Translate\'s token errors, except the one the phone reads, and the phone still tells them apart', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $ask = fn () => $this->api($token)->postJson('/api/live-translate/token', ['mode' => 'watch', 'target_language' => 'ur']);

    // No key: the phone reads "Soniox key" here, so it stays.
    $none = $ask()->assertStatus(503)->assertJsonPath('code', 'provider_not_configured')->json('message');
    expect($none)->toBe(LiveTranslateTokenController::NEEDS_KEY)->and(phoneShortReason($none))->toBe('Needs a Soniox key')
        ->and(phoneShortReasonV181('provider_not_configured', $none))->toBe('Not set up on the server');

    app(CredentialVault::class)->put('soniox', 'api_key', 'snx-account-key-1234567890');
    // What the provider answers this time.
    $reply = null;
    Http::fake(['api.soniox.com/*' => function (Request $request) use (&$reply) {
        return $reply($request);
    }]);
    $answers = [];
    // What v1.8.0 phones and v1.8.1 phones each say under Game and Watch.
    foreach ([401 => ['Needs a Soniox key', 'Not set up on the server'], 403 => ['Needs a Soniox key', 'Not set up on the server'],
        402 => ['Needs Soniox credit', 'Needs credit'], 500 => [null, null]] as $status => [$short, $short181]) {
        $reply = fn () => Http::response(['error' => 'refused'], $status);
        $answers[$status] = $ask()->assertStatus(502)->assertJsonPath('code', 'provider_error')->json('message');
        expect(phoneShortReason($answers[$status]))->toBe($short, "HTTP {$status}")
            ->and(phoneShortReasonV181('provider_error', $answers[$status]))->toBe($short181, "HTTP {$status}, v1.8.1");
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
        ->and(phoneShortReason($answers['no key']))->toBeNull()
        // A passing fault is not "not set up": v1.8.1 reads any "key" in a provider_error as that.
        ->and(phoneShortReasonV181('provider_error', $answers['unreachable']))->toBeNull()
        ->and(phoneShortReasonV181('provider_error', $answers['no key']))->toBeNull()
        ->and(phoneShortReasonV181('provider_error', $answers[500]))->toBeNull();
});
