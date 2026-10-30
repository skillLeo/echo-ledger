<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/** Every ability-protected device route: method, URI, required ability. */
function abilityRoutes(): array
{
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    return [
        'config' => ['GET', '/api/config', 'config:read'],
        'clients' => ['GET', '/api/clients', 'library:read'],
        'stt token' => ['POST', '/api/stt/token', 'stt:token'],
        'live translate token' => ['POST', '/api/live-translate/token', 'stt:token'],
        'usage report' => ['POST', '/api/usage', 'stt:token'],
        'create session' => ['POST', '/api/sessions', 'sessions:write'],
        'finish session' => ['POST', "/api/sessions/{$id}/finish", 'sessions:write'],
        'add bookmark' => ['POST', "/api/sessions/{$id}/markers", 'sessions:write'],
        're-transcribe as' => ['POST', "/api/sessions/{$id}/retranscribe", 'sessions:write'],
        'mark opened' => ['POST', "/api/sessions/{$id}/opened", 'library:read'],
        'delete bookmark' => ['DELETE', "/api/sessions/{$id}/markers/{$id}", 'sessions:write'],
        'rename speakers' => ['PUT', "/api/sessions/{$id}/speakers", 'sessions:write'],
        'edit transcript' => ['PATCH', "/api/transcripts/{$id}", 'sessions:write'],
        'replace in transcript' => ['POST', "/api/transcripts/{$id}/replace", 'sessions:write'],
        'restore transcript' => ['POST', "/api/transcripts/{$id}/restore", 'sessions:write'],
        'transcript revisions' => ['GET', "/api/transcripts/{$id}/revisions", 'library:read'],
        'search' => ['GET', '/api/search', 'library:read'],
        'export transcript' => ['GET', "/api/transcripts/{$id}/export", 'library:read'],
        'show as views' => ['GET', "/api/transcripts/{$id}/views", 'library:read'],
        'make show as views' => ['POST', "/api/transcripts/{$id}/views", 'sessions:write'],
        'list share links' => ['GET', "/api/sessions/{$id}/shares", 'library:read'],
        'make share link' => ['POST', "/api/sessions/{$id}/shares", 'sessions:write'],
        'revoke share link' => ['DELETE', "/api/shares/{$id}", 'sessions:write'],
        'vocabulary' => ['GET', '/api/vocabulary', 'library:read'],
        'add keyterm' => ['POST', '/api/vocabulary/keyterms', 'sessions:write'],
        'add replacement' => ['POST', '/api/vocabulary/replacements', 'sessions:write'],
        'organise session' => ['PATCH', "/api/sessions/{$id}", 'sessions:write'],
        'bulk action' => ['POST', '/api/sessions/bulk', 'sessions:write'],
        'bulk export' => ['POST', '/api/sessions/export', 'library:read'],
        'smart views' => ['GET', '/api/library/views', 'library:read'],
        'create client' => ['POST', '/api/clients', 'sessions:write'],
        'update client' => ['PATCH', "/api/clients/{$id}", 'sessions:write'],
        'security centre' => ['GET', '/api/security', 'config:read'],
        'revoke device' => ['DELETE', "/api/devices/{$id}", 'sessions:write'],
        'undo bin' => ['GET', '/api/bin', 'library:read'],
        'storage meter' => ['GET', '/api/storage', 'library:read'],
        'restore session' => ['POST', "/api/sessions/{$id}/restore", 'sessions:write'],
        'erase from bin' => ['DELETE', "/api/bin/{$id}", 'sessions:write'],
        'activity' => ['GET', '/api/activity', 'library:read'],
        'mark activity read' => ['POST', '/api/activity/read', 'library:read'],
        'add recording' => ['POST', "/api/sessions/{$id}/recordings", 'sessions:write'],
        'post transcript' => ['POST', "/api/sessions/{$id}/transcripts", 'sessions:write'],
        'delete session' => ['DELETE', "/api/sessions/{$id}", 'sessions:write'],
        'recording status' => ['GET', "/api/recordings/{$id}", 'recordings:upload'],
        'upload chunk' => ['POST', "/api/recordings/{$id}/chunks", 'recordings:upload'],
        'complete upload' => ['POST', "/api/recordings/{$id}/complete", 'recordings:upload'],
        'library' => ['GET', '/api/library', 'library:read'],
        'session detail' => ['GET', "/api/sessions/{$id}", 'library:read'],
        'transcript' => ['GET', "/api/transcripts/{$id}", 'library:read'],
    ];
}

dataset('ability routes', fn () => abilityRoutes());

describe('route coverage', function () {
    it('has an ability test for every authenticated API route', function () {
        $declared = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->methods()[0].' /'.preg_replace('/\{[^}]+\}/', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $route->uri()))
            ->sort()->values()->all();

        $tested = collect(abilityRoutes())->map(fn (array $row) => $row[0].' '.$row[1])->push('POST /api/devices/rotate-token')->sort()->values()->all();

        expect($declared)->toBe($tested);
    });

    it('leaves only registration, signed audio and provider webhooks reachable without a token', function () {
        $open = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && ! in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->methods()[0].' /'.$route->uri())
            ->sort()->values()->all();

        expect($open)->toBe([
            'GET /api/recordings/{recording}/audio',
            'POST /api/devices/register',
            'POST /api/webhooks/stt/{provider}',
        ]);
    });

    it('guards every authenticated route with an explicit ability except token rotation', function () {
        $unguarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'abilities:')))
            ->map(fn ($route) => $route->uri())->values()->all();

        expect($unguarded)->toBe(['api/devices/rotate-token']);
    });
});

describe('abilities', function () {
    it('answers 403 when the token lacks the route ability', function (string $method, string $uri, string $ability) {
        [, $token] = $this->enroll(abilities: array_values(array_diff(Device::ABILITIES, [$ability])));

        $this->asToken($token)->json($method, $uri)->assertForbidden()->assertJsonStructure(['message']);
    })->with('ability routes');

    it('answers 403 for a token with a different single ability', function (string $method, string $uri, string $ability) {
        $other = collect(Device::ABILITIES)->first(fn (string $a) => $a !== $ability);
        [, $token] = $this->enroll(abilities: [$other]);

        $this->asToken($token)->json($method, $uri)->assertForbidden();
    })->with('ability routes');

    it('answers 403 for a token with no abilities at all', function (string $method, string $uri) {
        [, $token] = $this->enroll(abilities: []);

        $this->asToken($token)->json($method, $uri)->assertForbidden();
    })->with('ability routes');

    it('lets a token that carries only the required ability past authorization', function (string $method, string $uri, string $ability) {
        [, $token] = $this->enroll(abilities: [$ability]);

        $status = $this->asToken($token)->json($method, $uri)->getStatusCode();

        expect($status)->not->toBe(401)->not->toBe(403);
    })->with('ability routes');

    it('answers 401 without a token', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    })->with('ability routes');

    it('treats a wildcard token as unrestricted but only when issued that way', function () {
        [, $token] = $this->enroll(abilities: ['*']);

        $this->asToken($token)->getJson('/api/config')->assertOk();
    });

    it('does not let an ability for one resource stand in for another with a similar name', function () {
        [, $token] = $this->enroll(abilities: ['library:write', 'config', 'sessions:read', 'recordings']);

        $this->asToken($token)->getJson('/api/library')->assertForbidden();
        $this->asToken($token)->postJson('/api/sessions', [])->assertForbidden();
    });
});

describe('authentication', function () {
    it('rejects malformed or unknown credentials', function (array $headers) {
        $this->withHeaders($headers)->getJson('/api/config')->assertUnauthorized();
    })->with([
        'empty bearer' => [['Authorization' => 'Bearer ']],
        'scheme only' => [['Authorization' => 'Bearer']],
        'unknown token' => [['Authorization' => 'Bearer 1|eld_doesnotexist']],
        'garbage' => [['Authorization' => 'Bearer not-a-token']],
        'basic auth' => [['Authorization' => 'Basic dXNlcjpwYXNz']],
        'raw token without scheme' => [['Authorization' => '1|eld_abcdefghijklmnop']],
    ]);

    it('does not accept the token in the query string', function () {
        [, $token] = $this->enroll();

        $this->getJson('/api/config?token='.urlencode($token))->assertUnauthorized();
        $this->getJson('/api/config?access_token='.urlencode($token))->assertUnauthorized();
    });

    it('rejects a token whose secret part was altered', function () {
        [, $token] = $this->enroll();
        $tampered = substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a');

        $this->asToken($tampered)->getJson('/api/config')->assertUnauthorized();
    });

    it('rejects a valid secret presented under another token id', function () {
        [, $first] = $this->enroll();
        [, $second] = $this->enroll();
        $secret = explode('|', $first, 2)[1];
        $id = explode('|', $second, 2)[0];

        $this->asToken($id.'|'.$secret)->getJson('/api/config')->assertUnauthorized();
    });

    it('returns a JSON 401, never a redirect, even for a browser-style request', function () {
        $this->get('/api/config', ['Accept' => 'text/html'])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    });

    it('stops accepting a token the moment it expires', function () {
        $this->freezeTime();
        [$device] = $this->enroll();
        $token = $device->createToken('short', Device::ABILITIES, now()->addMinutes(5))->plainTextToken;

        $this->asToken($token)->getJson('/api/config')->assertOk();

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();
        $this->asToken($token)->getJson('/api/config')->assertUnauthorized();
    });

    it('rejects a token that expired in the past', function () {
        [$device] = $this->enroll();
        $token = $device->createToken('old', Device::ABILITIES, now()->subSecond())->plainTextToken;

        $this->asToken($token)->getJson('/api/config')->assertUnauthorized();
    });

    it('honours the configured token lifetime for new devices', function () {
        $this->freezeTime();
        config(['echoledger.device_token_days' => 30]);
        [$device] = $this->enroll();
        $issued = app(PairingService::class)->issueToken($device);

        expect($issued->accessToken->expires_at->getTimestamp())->toBe(now()->addDays(30)->getTimestamp());
    });

    it('records the time a token was last used', function () {
        [, $token] = $this->enroll();
        expect(PersonalAccessToken::query()->sole()->last_used_at)->toBeNull();

        $this->asToken($token)->getJson('/api/config')->assertOk();

        expect(PersonalAccessToken::query()->sole()->last_used_at)->not->toBeNull();
    });
});

describe('rotation', function () {
    it('issues a new token and revokes the old one immediately', function () {
        [$device, $old] = $this->enroll();

        $response = $this->asToken($old)->postJson('/api/devices/rotate-token')->assertOk()
            ->assertJsonStructure(['token', 'token_expires_at']);
        $new = $response->json('token');

        expect($new)->not->toBe($old)->and($new)->toMatch('/^\d+\|eld_[A-Za-z0-9]+$/');
        $this->asToken($old)->getJson('/api/config')->assertUnauthorized();
        $this->asToken($new)->getJson('/api/config')->assertOk()->assertJsonPath('device.id', $device->id);
    });

    it('keeps exactly one token for the device, with the standard abilities and a fresh expiry', function () {
        $this->freezeTime();
        [$device, $old] = $this->enroll();
        $this->travel(10)->days();

        $response = $this->asToken($old)->postJson('/api/devices/rotate-token')->assertOk();

        $tokens = PersonalAccessToken::query()->get();
        expect($tokens)->toHaveCount(1)
            ->and($tokens->first()->tokenable_id)->toBe($device->id)
            ->and($tokens->first()->abilities)->toEqualCanonicalizing(Device::ABILITIES)
            ->and($tokens->first()->expires_at->getTimestamp())->toBe(now()->addDays(365)->getTimestamp())
            ->and(strtotime($response->json('token_expires_at')))->toBe(now()->addDays(365)->getTimestamp());
    });

    it('can be repeated, and each generation revokes the previous one', function () {
        [, $token] = $this->enroll();
        $generations = [$token];
        foreach (range(1, 3) as $_) {
            $generations[] = $this->asToken(end($generations))->postJson('/api/devices/rotate-token')->assertOk()->json('token');
        }

        foreach (array_slice($generations, 0, 3) as $retired) {
            $this->asToken($retired)->getJson('/api/config')->assertUnauthorized();
        }
        $this->asToken($generations[3])->getJson('/api/config')->assertOk();
        expect(PersonalAccessToken::query()->count())->toBe(1);
    });

    it('revokes only the token presented, not the device\'s other tokens or other devices', function () {
        [$deviceA, $tokenA] = $this->enroll();
        $secondTokenOfA = $deviceA->createToken('second', Device::ABILITIES, now()->addDay())->plainTextToken;
        [, $tokenB] = $this->enroll();

        $this->asToken($tokenA)->postJson('/api/devices/rotate-token')->assertOk();

        $this->asToken($tokenA)->getJson('/api/config')->assertUnauthorized();
        $this->asToken($secondTokenOfA)->getJson('/api/config')->assertOk();
        $this->asToken($tokenB)->getJson('/api/config')->assertOk();
    });

    it('gives the new token no more abilities than the token that asked for it', function () {
        [, $limited] = $this->enroll(abilities: ['library:read']);

        $new = $this->asToken($limited)->postJson('/api/devices/rotate-token')->assertOk()->json('token');

        expect(PersonalAccessToken::findToken($new)->abilities)->toBe(['library:read']);
        $this->asToken($new)->postJson('/api/sessions', [])->assertForbidden();
    });

    it('requires authentication', function () {
        $this->postJson('/api/devices/rotate-token')->assertUnauthorized();
    });

    it('does not carry over an expired token', function () {
        [$device] = $this->enroll();
        $expired = $device->createToken('old', Device::ABILITIES, now()->subMinute())->plainTextToken;

        $this->asToken($expired)->postJson('/api/devices/rotate-token')->assertUnauthorized();
    });

    it('does not expose the secret of the new token anywhere else', function () {
        [, $old] = $this->enroll();
        $response = $this->asToken($old)->postJson('/api/devices/rotate-token')->assertOk();
        $secret = explode('|', $response->json('token'), 2)[1];

        expect(PersonalAccessToken::query()->sole()->token)->not->toContain($secret);
    });
});

describe('revocation', function () {
    it('invalidates every token of a device that is removed', function () {
        [$device, $first] = $this->enroll();
        $second = $device->createToken('second', Device::ABILITIES)->plainTextToken;
        [, $untouched] = $this->enroll();

        $device->delete();

        $this->asToken($first)->getJson('/api/config')->assertUnauthorized();
        $this->asToken($second)->getJson('/api/config')->assertUnauthorized();
        $this->asToken($untouched)->getJson('/api/config')->assertOk();
        expect(PersonalAccessToken::query()->where('tokenable_id', $device->id)->count())->toBe(0);
    });

    it('invalidates a single revoked token without touching the device', function () {
        [$device, $token] = $this->enroll();

        $device->tokens()->delete();

        $this->asToken($token)->getJson('/api/config')->assertUnauthorized();
        expect(Device::query()->find($device->id))->not->toBeNull();
    });

    it('keeps the sessions of a revoked device in the library, detached from it', function () {
        [$revoked] = $this->enroll($owner = User::factory()->create());
        [, $survivor] = $this->enroll($owner);
        $session = CaptureSession::factory()->create(['user_id' => $owner->id, 'device_id' => $revoked->id]);

        $revoked->delete();

        $item = collect($this->asToken($survivor)->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $session->id);
        expect($item)->not->toBeNull()->and($item['device'])->toBeNull();
    });

    it('stops a revoked device from uploading, even mid-upload', function () {
        [$device, $token] = $this->enroll();
        $bytes = Fixtures::audio(2048);
        $created = $this->asToken($token)->createSession($bytes)->assertCreated();
        $recording = $created->json('recordings.0.id');

        $device->delete();

        $this->asToken($token)->postChunk($recording, 0, $bytes)->assertUnauthorized();
        $this->asToken($token)->postJson("/api/recordings/{$recording}/complete")->assertUnauthorized();
    });
});

describe('last seen tracking', function () {
    it('updates last seen and address when the previous value is more than a minute old', function () {
        $this->freezeTime();
        [$device, $token] = $this->enroll();
        $device->forceFill(['last_seen_at' => now()->subMinutes(5), 'last_ip' => '192.0.2.1'])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->asToken($token)->getJson('/api/config')->assertOk();

        $device->refresh();
        expect($device->last_seen_at->getTimestamp())->toBe(now()->getTimestamp())->and($device->last_ip)->toBe('203.0.113.50');
    });

    it('does not write again within the same minute', function () {
        $this->freezeTime();
        [$device, $token] = $this->enroll();
        $seen = now()->subSeconds(20);
        $device->forceFill(['last_seen_at' => $seen, 'last_ip' => '192.0.2.1'])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->asToken($token)->getJson('/api/config')->assertOk();

        $device->refresh();
        expect($device->last_seen_at->getTimestamp())->toBe($seen->getTimestamp())->and($device->last_ip)->toBe('192.0.2.1');
    });

    it('sets last seen for a device that has never been seen', function () {
        [$device, $token] = $this->enroll();
        $device->forceFill(['last_seen_at' => null])->save();

        $this->asToken($token)->getJson('/api/config')->assertOk();

        expect($device->refresh()->last_seen_at)->not->toBeNull();
    });
});

describe('rate limits', function () {
    it('defines the documented budgets', function (string $name, int $perMinute) {
        $request = Request::create('/api/x');
        $request->setUserResolver(fn () => Device::factory()->make(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']));

        /** @var Limit $limit */
        $limit = RateLimiter::limiter($name)($request);

        expect($limit->maxAttempts)->toBe($perMinute)->and($limit->decaySeconds)->toBe(60);
    })->with([
        'device' => ['device', 240],
        'uploads' => ['uploads', 600],
        'stt-token' => ['stt-token', 30],
        'pairing' => ['pairing', 5],
        'webhooks' => ['webhooks', 300],
    ]);

    it('keys the authenticated limits on the device so two devices behind one address do not share a budget', function () {
        $request = Request::create('/api/x');
        $request->setUserResolver(fn () => Device::factory()->make(['id' => 'DEVICE_A']));
        $other = Request::create('/api/x');
        $other->setUserResolver(fn () => Device::factory()->make(['id' => 'DEVICE_B']));

        foreach (['device', 'uploads', 'stt-token'] as $name) {
            expect(RateLimiter::limiter($name)($request)->key)->not->toBe(RateLimiter::limiter($name)($other)->key);
        }
    });

    it('answers 429 with Retry-After after 240 requests a minute and leaves other devices unaffected', function () {
        $this->freezeTime();
        [, $busy] = $this->enroll();
        [, $quiet] = $this->enroll();

        foreach (range(1, 240) as $_) {
            $this->asToken($busy)->getJson('/api/clients')->assertOk();
        }
        $blocked = $this->asToken($busy)->getJson('/api/clients');

        $blocked->assertStatus(429);
        expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
        $this->asToken($quiet)->getJson('/api/clients')->assertOk();
    });

    it('lets a rotated token keep sharing the device budget', function () {
        $this->freezeTime();
        [, $token] = $this->enroll();
        foreach (range(1, 239) as $_) {
            $this->asToken($token)->getJson('/api/clients')->assertOk();
        }
        $rotated = $this->asToken($token)->postJson('/api/devices/rotate-token')->assertOk()->json('token'); // the 240th

        $this->asToken($rotated)->getJson('/api/clients')->assertStatus(429);
    });
});
