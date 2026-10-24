<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\PairingService;

it('registers a device with a one-time pairing code and derives the source device', function () {
    $user = User::factory()->create();
    $code = app(PairingService::class)->createCode($user);

    $response = $this->postJson('/api/devices/register', [
        'pairing_code' => $code,
        'name' => 'My Pixel',
        'platform' => 'android',
        'app_version' => '0.1.0',
        'os_version' => '15',
    ]);

    $response->assertCreated()
        ->assertJsonPath('device.source_device', 'phone')
        ->assertJsonStructure(['device' => ['id', 'name', 'source_device'], 'token', 'config' => ['upload', 'retention_days']]);

    expect($response->json('token'))->toContain('eld_'); // Sanctum prefixes the token id: "{id}|eld_…"
    $this->assertDatabaseHas('devices', ['name' => 'My Pixel']);
});

it('rejects a reused pairing code', function () {
    $user = User::factory()->create();
    $code = app(PairingService::class)->createCode($user);
    $payload = ['pairing_code' => $code, 'name' => 'A', 'platform' => 'android'];

    $this->postJson('/api/devices/register', $payload)->assertCreated();
    $this->postJson('/api/devices/register', $payload)->assertStatus(422);
});

it('rejects an unknown pairing code', function () {
    $this->postJson('/api/devices/register', ['pairing_code' => 'ZZZZ-ZZZZ', 'name' => 'A', 'platform' => 'android'])
        ->assertStatus(422);
});

it('requires a bearer token for the config endpoint', function () {
    $this->getJson('/api/config')->assertUnauthorized();
});
