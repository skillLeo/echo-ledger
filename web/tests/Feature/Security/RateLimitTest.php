<?php

declare(strict_types=1);

use App\Models\Device;
use App\Services\PairingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/** Sends `$times` requests from one address and returns the status codes in order. */
function secBurst(Closure $send, int $times): array
{
    $statuses = [];
    for ($i = 0; $i < $times; $i++) {
        $statuses[] = $send($i)->getStatusCode();
    }

    return $statuses;
}

it('limits pairing attempts to five a minute per address and answers 429 with Retry-After', function () {
    $send = fn () => $this->api()->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android']);

    $statuses = secBurst($send, 6);

    expect(array_slice($statuses, 0, 5))->each->toBe(422)
        ->and($statuses[5])->toBe(429);
    $blocked = $send();
    expect($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($blocked->headers->get('X-RateLimit-Limit'))->toBe('5');
});

it('keeps a valid pairing code unusable while the address is throttled', function () {
    $code = app(PairingService::class)->createCode(Fx::user());
    $server = ['REMOTE_ADDR' => '203.0.113.8'];
    for ($i = 0; $i < 5; $i++) {
        $this->api()->withServerVariables($server)->postJson('/api/devices/register', ['pairing_code' => 'BBBB-BBBB', 'name' => 'x', 'platform' => 'android']);
    }

    $this->api()->withServerVariables($server)->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'x', 'platform' => 'android'])->assertStatus(429);

    expect(Device::query()->count())->toBe(0);
});

it('does not let a forwarded-for header buy extra pairing attempts', function () {
    $statuses = secBurst(fn (int $i) => $this->api()->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.'.($i + 1), 'X-Real-IP' => '198.51.100.'.($i + 1), 'Forwarded' => 'for=198.51.100.'.($i + 1)])
        ->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android']), 8);

    expect($statuses[5])->toBe(429)->and($statuses[7])->toBe(429);
});

it('tracks pairing attempts per address, not globally', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->api()->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android']);
    }

    $this->api()->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android'])->assertStatus(422);
});

it('lets pairing resume after the window passes', function () {
    $send = fn () => $this->api()->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android']);
    secBurst($send, 6);
    expect($send()->getStatusCode())->toBe(429);

    $this->travel(61)->seconds();

    expect($send()->getStatusCode())->toBe(422);
});

it('limits each device to 240 requests a minute without affecting other devices', function () {
    $busy = Fx::token(Fx::device());
    $quiet = Fx::token(Fx::device());

    $statuses = secBurst(fn () => $this->api($busy)->getJson('/api/config'), 241);

    expect(array_slice($statuses, 0, 240))->each->toBe(200)->and($statuses[240])->toBe(429);
    $this->api($quiet)->getJson('/api/config')->assertOk();
});

it('answers a throttled device with Retry-After and rate-limit headers', function () {
    $token = Fx::token(Fx::device());
    secBurst(fn () => $this->api($token)->getJson('/api/clients'), 240);

    $blocked = $this->api($token)->getJson('/api/clients');

    $blocked->assertStatus(429);
    expect($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and($blocked->headers->get('X-RateLimit-Limit'))->toBe('240')
        ->and($blocked->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

it('cannot be reset by rotating the token because the limit follows the device', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    secBurst(fn () => $this->api($token)->getJson('/api/clients'), 240);

    $fresh = Fx::token($device);

    $this->api($fresh)->getJson('/api/clients')->assertStatus(429);
});

it('limits streaming-token minting to 30 requests a minute per device', function () {
    Http::fake(['*' => Http::response(['token' => 'tok'], 200)]);
    $token = Fx::token(Fx::device());
    $other = Fx::token(Fx::device());

    $statuses = secBurst(fn () => $this->api($token)->postJson('/api/stt/token', ['use_case' => 'bogus']), 31);

    expect(array_slice($statuses, 0, 30))->each->toBe(422)->and($statuses[30])->toBe(429);
    $this->api($other)->postJson('/api/stt/token', ['use_case' => 'bogus'])->assertStatus(422);
});

it('limits the webhook endpoint to 300 requests a minute per address', function () {
    $send = fn () => $this->api()->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->postJson('/api/webhooks/stt/unknown', []);

    $statuses = secBurst($send, 301);

    expect(array_slice($statuses, 0, 300))->each->toBe(404)->and($statuses[300])->toBe(429);
    $this->api()->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])->postJson('/api/webhooks/stt/unknown', [])->assertStatus(404);
});

it('lets the uploads limiter, not the general device limit, govern chunk traffic', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $recording = Fx::pendingRecording(Fx::session($device->user, $device), str_repeat('x', 2048), 1024);

    $statuses = secBurst(fn () => $this->api($token)->getJson("/api/recordings/{$recording->id}"), 300);

    // The `uploads` limiter allows 600 a minute. If a stricter limiter sits in front of it, it can never be reached.
    expect(array_count_values($statuses)[429] ?? 0)->toBe(0);
});

it('throttles the signed audio route by address', function () {
    $send = fn () => $this->api(null, false)->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
        ->get('/api/recordings/'.Fx::MISSING_ID.'/audio');

    $statuses = secBurst($send, 5);

    expect($statuses)->each->toBe(403);
});

it('registers the five documented named rate limiters', function () {
    foreach (['pairing', 'device', 'uploads', 'stt-token', 'webhooks'] as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull($name);
    }
});
