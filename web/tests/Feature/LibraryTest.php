<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Models\CaptureSession;
use App\Models\Device;
use Laravel\Sanctum\Sanctum;

it('lists sessions from every device with icon + text labels', function () {
    $device = Device::factory()->create();
    $user = $device->user;

    CaptureSession::factory()->for($user)->create(['source_device' => SourceDevice::Phone, 'source_app' => SourceApp::WhatsApp]);
    CaptureSession::factory()->for($user)->create(['source_device' => SourceDevice::Desktop, 'source_app' => SourceApp::Zoom]);
    CaptureSession::factory()->for($user)->create(['source_device' => SourceDevice::Web, 'source_app' => SourceApp::VoiceNote]);

    Sanctum::actingAs($device, Device::ABILITIES);
    $response = $this->getJson('/api/library');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);

    $devices = collect($response->json('data'))->pluck('source_device')->sort()->values()->all();
    expect($devices)->toBe(['desktop', 'phone', 'web']);

    $first = $response->json('data.0');
    expect($first)->toHaveKeys(['source_device_label', 'source_device_icon', 'source_app_label']);
    expect($first['deleted'])->toBeFalse();
});

it('only shows the authenticated owner\'s sessions', function () {
    $mine = Device::factory()->create();
    CaptureSession::factory()->for($mine->user)->create();
    CaptureSession::factory()->create(); // a different owner

    Sanctum::actingAs($mine, Device::ABILITIES);
    expect($this->getJson('/api/library')->json('data'))->toHaveCount(1);
});

it('returns a tombstone for a deleted session when syncing with since', function () {
    $device = Device::factory()->create();
    $session = CaptureSession::factory()->for($device->user)->create();
    $since = now()->subMinute()->toAtomString();
    $session->delete();

    Sanctum::actingAs($device, Device::ABILITIES);
    $data = collect($this->getJson('/api/library?since='.urlencode($since))->json('data'));

    $tombstone = $data->firstWhere('id', $session->id);
    expect($tombstone)->not->toBeNull();
    expect($tombstone['deleted'])->toBeTrue();
});

it('filters by source device', function () {
    $device = Device::factory()->create();
    CaptureSession::factory()->for($device->user)->create(['source_device' => SourceDevice::Phone]);
    CaptureSession::factory()->for($device->user)->create(['source_device' => SourceDevice::Desktop]);

    Sanctum::actingAs($device, Device::ABILITIES);
    $data = $this->getJson('/api/library?source=desktop')->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['source_device'])->toBe('desktop');
});
