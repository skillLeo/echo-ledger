<?php

declare(strict_types=1);

use App\Services\CredentialVault;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Live Translate (v1.3, Phase B): the phone asks for a short-lived Soniox key and streams to Soniox itself. The
 * account key never leaves the server; Soniox is faked here.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

it('mints a temporary Soniox key with the translation settings and never returns the account key', function () {
    app(CredentialVault::class)->put('soniox', 'api_key', 'snx-account-key-1234567890');
    Http::fake(['api.soniox.com/v1/auth/temporary-api-key' => Http::response(['api_key' => 'temp:abc123', 'expires_at' => '2026-10-31T10:10:00Z'])]);

    $response = $this->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en', 'language_hints' => ['ar', 'ar']])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'provider' => 'soniox',
            'api_key' => 'temp:abc123',
            'expires_at' => '2026-10-31T10:10:00Z',
            'websocket_url' => 'wss://stt-rt.soniox.com/transcribe-websocket',
            'model' => 'stt-rt-v5',
            'translation' => ['type' => 'one_way', 'target_language' => 'en'],
            'language_hints' => ['ar'],
        ]);

    expect($response->getContent())->not->toContain('snx-account-key');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer snx-account-key-1234567890')
        && $r['usage_type'] === 'transcribe_websocket' && $r['expires_in_seconds'] === 600);
});

it('says what to do when there is no Soniox key, or Soniox refuses', function () {
    $this->postJson('/api/live-translate/token', ['mode' => 'watch', 'target_language' => 'ur'])
        ->assertStatus(503)->assertJsonPath('code', 'provider_not_configured')
        ->assertJsonPath('message', 'Live Translate needs a Soniox key. Add it in API Credentials, then try again.');

    app(CredentialVault::class)->put('soniox', 'api_key', 'snx-account-key-1234567890');
    Http::fake(['api.soniox.com/*' => Http::response(['error' => 'payment required'], 402)]);
    $this->postJson('/api/live-translate/token', ['mode' => 'watch', 'target_language' => 'ur'])
        ->assertStatus(502)->assertJsonPath('message', 'Soniox has no credit left. Add credit to the Soniox account, then try again.');
});

it('accepts only the two modes and two-letter languages', function () {
    $this->postJson('/api/live-translate/token', ['mode' => 'karaoke', 'target_language' => 'english'])
        ->assertStatus(422)->assertJsonValidationErrors(['mode', 'target_language']);
});
