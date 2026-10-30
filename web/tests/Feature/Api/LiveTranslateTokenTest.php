<?php

declare(strict_types=1);

use App\Services\CredentialVault;
use App\Services\SettingsRepository;
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

it('keeps Live Translate inside its own monthly budget, unless the person continues anyway', function () {
    app(CredentialVault::class)->put('soniox', 'api_key', 'snx-account-key-1234567890');
    Http::fake(['api.soniox.com/v1/auth/temporary-api-key' => Http::response(['api_key' => 'temp:abc123', 'expires_at' => '2026-10-31T10:10:00Z'])]);
    app(SettingsRepository::class)->set('live_translate.monthly_usd', 1.0);
    $report = fn (string $id, string $useCase, int $ms) => $this->postJson('/api/usage', [
        'report_id' => $id, 'use_case' => $useCase, 'provider' => 'soniox', 'streamed_ms' => $ms, 'channels' => 1,
    ])->assertCreated();

    $report('lt-game-000001', 'game_translate', 3_600_000 * 5); // 5 h × $0.18 = $0.90: a warning
    $this->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en'])
        ->assertOk()->assertJsonPath('budget.warn', true)->assertJsonPath('budget.reached', false);

    $report('lt-watch-00001', 'watch_translate', 3_600_000 * 2);
    $this->postJson('/api/live-translate/token', ['mode' => 'watch', 'target_language' => 'ur'])
        ->assertStatus(403)->assertJsonPath('code', 'budget_exceeded')
        ->assertJsonPath('message', "Live Translate's budget of $1.00 for this month is used up. Raise it in Settings → Game translate, or continue this month anyway.");
    $this->postJson('/api/live-translate/token', ['mode' => 'watch', 'target_language' => 'ur', 'continue_over_budget' => true])
        ->assertOk()->assertJsonPath('budget.reached', true);
});

it('counts Live Translate apart from the transcription limit, and only as Soniox', function () {
    app(SettingsRepository::class)->set('budget.monthly_usd', 0.5);
    $this->postJson('/api/usage', ['report_id' => 'lt-game-000002', 'use_case' => 'game_translate', 'provider' => 'soniox', 'streamed_ms' => 3_600_000 * 10, 'channels' => 1])
        ->assertCreated()
        ->assertJsonPath('report.cost_usd', 1.8)
        ->assertJsonPath('usage.limit_reached', false)
        ->assertJsonPath('live_translate.used_usd', 1.8);

    $this->postJson('/api/usage', ['report_id' => 'lt-game-000003', 'use_case' => 'game_translate', 'provider' => 'assemblyai', 'streamed_ms' => 1000, 'channels' => 1])
        ->assertStatus(422)->assertJsonValidationErrors(['provider']);
});
