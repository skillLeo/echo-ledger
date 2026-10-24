<?php

declare(strict_types=1);

use App\Models\Device;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

const PROVIDER_KEY = 'aai_secret_do_not_leak';

it('returns short-lived grants and never leaks the provider key', function () {
    app(CredentialVault::class)->put('assemblyai', 'api_key', PROVIDER_KEY);
    Http::fake(['*' => Http::response(['token' => 'tmp_tok_abc'], 200)]);

    $device = Device::factory()->create();
    Sanctum::actingAs($device, Device::ABILITIES);

    $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me', 'client']]);

    $response->assertOk()->assertJsonPath('provider', 'assemblyai');
    expect($response->json('grants'))->toHaveCount(2);

    $body = $response->getContent();
    expect($body)->toContain('tmp_tok_abc');
    expect($body)->not->toContain(PROVIDER_KEY);
    expect($response->headers->get('Cache-Control'))->toContain('no-store'); // Symfony appends ", private"

    // The key is sent to the provider, as the raw Authorization header (no "Bearer").
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', PROVIDER_KEY) && str_contains($request->url(), '/v3/token'));
});

it('returns an off grant when captions are disabled server-side', function () {
    app(SettingsRepository::class)->set('captions.channels', 'off');
    $device = Device::factory()->create();
    Sanctum::actingAs($device, Device::ABILITIES);

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])
        ->assertOk()
        ->assertJsonPath('mode', 'off')
        ->assertJsonPath('grants', []);
});
