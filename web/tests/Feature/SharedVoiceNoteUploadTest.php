<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** A voice note shared into the phone app has no known sample rate; the app sends 0. It must still upload. */
it('accepts an artifact whose sample rate is 0 (unknown) instead of rejecting the upload', function () {
    Sanctum::actingAs(Device::factory()->create(), Device::ABILITIES);

    $response = $this->postJson('/api/sessions', [
        'client_ref' => (string) Str::uuid(),
        'source_app' => 'voice_note',
        'started_at' => now()->subMinute()->toAtomString(),
        'artifacts' => [[
            'role' => 'primary',
            'mime' => 'audio/ogg',
            'codec' => 'opus',
            'channels' => 1,
            'sample_rate' => 0,
            'size_bytes' => 4096,
            'sha256' => str_repeat('a', 64),
        ]],
    ]);

    $response->assertCreated();
    expect($response->json('recordings.0.role'))->toBe('primary');
});

it('still rejects a genuinely invalid sample rate', function () {
    Sanctum::actingAs(Device::factory()->create(), Device::ABILITIES);

    $this->postJson('/api/sessions', [
        'client_ref' => (string) Str::uuid(),
        'source_app' => 'voice_note',
        'started_at' => now()->subMinute()->toAtomString(),
        'artifacts' => [[
            'role' => 'primary', 'mime' => 'audio/ogg', 'channels' => 1,
            'sample_rate' => 100, 'size_bytes' => 4096, 'sha256' => str_repeat('a', 64),
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors(['artifacts.0.sample_rate']);
});
