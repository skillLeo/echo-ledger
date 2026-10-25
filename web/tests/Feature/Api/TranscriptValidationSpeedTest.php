<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "POST /api/sessions/{id}/transcripts": at most 20 000 segments. The cap must be reachable: validation is
 * linear, so a full-size upload is checked (and an oversize one refused) in well under the request timeout.
 */

beforeEach(function () {
    [$this->device, $token] = $this->enroll(platform: 'windows');
    $this->asToken($token);
    $this->session = CaptureSession::factory()->create([
        'user_id' => $this->device->user_id, 'device_id' => $this->device->id,
        'duration_ms' => 20000 * 3000, 'status' => SessionStatus::Uploading,
    ]);
    $this->url = "/api/sessions/{$this->session->id}/transcripts";
});

/** The time budget: 2 s alone; parallel test workers share the CPU, so they get twice that. */
function validationBudget(): float
{
    return getenv('LARAVEL_PARALLEL_TESTING') || ! empty($_SERVER['LARAVEL_PARALLEL_TESTING']) || getenv('TEST_TOKEN') !== false ? 4.0 : 2.0;
}

/** @return list<array<string, mixed>> */
function captionSegments(int $count): array
{
    $segments = [];
    for ($i = 0; $i < $count; $i++) {
        $segments[] = ['channel' => $i % 2 + 1, 'start_ms' => $i * 3000, 'end_ms' => $i * 3000 + 2500, 'text' => "line {$i}",
            'words' => [['text' => 'line', 'start_ms' => $i * 3000, 'end_ms' => $i * 3000 + 900, 'confidence' => 0.9]]];
    }

    return $segments;
}

it('accepts 20,000 segments in under two seconds', function () {
    $body = ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => captionSegments(20000)];

    $started = hrtime(true);
    $response = $this->postJson($this->url, $body);
    $seconds = (hrtime(true) - $started) / 1e9;

    $response->assertCreated();
    expect(Transcript::query()->sole()->segments)->toHaveCount(20000)
        ->and($seconds)->toBeLessThan(validationBudget(), "took {$seconds} s");
});

it('refuses 20,001 segments quickly', function () {
    $body = ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => captionSegments(20001)];

    $started = hrtime(true);
    $this->postJson($this->url, $body)->assertStatus(422)->assertJsonValidationErrors(['segments']);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(validationBudget())
        ->and(Transcript::query()->count())->toBe(0);
});

it('points at the first bad segment of a full-size upload', function () {
    $segments = captionSegments(20000);
    $segments[19998]['channel'] = 3;
    $segments[19999]['text'] = '';

    $this->postJson($this->url, ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => $segments])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['segments.19998.channel'])
        ->assertJsonMissingValidationErrors(['segments.19999.text']);
});

it('checks every word of a segment', function (mixed $word, string $field) {
    $segments = captionSegments(3);
    $segments[2]['words'][] = $word;

    $this->postJson($this->url, ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => $segments])
        ->assertStatus(422)->assertJsonValidationErrors(["segments.2.words.1{$field}"]);
})->with([
    'not an object' => ['word', ''],
    'text too long' => [['text' => str_repeat('w', 1001)], '.text'],
    'negative start' => [['start_ms' => -5], '.start_ms'],
    'end as text' => [['end_ms' => 'later'], '.end_ms'],
    'confidence above one' => [['confidence' => 1.5], '.confidence'],
    'confidence as text' => [['confidence' => 'high'], '.confidence'],
]);
