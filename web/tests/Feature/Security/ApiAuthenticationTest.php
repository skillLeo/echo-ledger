<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/**
 * Routes behind the device guard, with the Sanctum ability each one requires (null = any valid token).
 *
 * @return array<string, array{0: string, 1: string, 2: string|null}>
 */
function secDeviceGuardedRoutes(): array
{
    return [
        'POST devices/rotate-token' => ['POST', '/api/devices/rotate-token', null],
        'GET config' => ['GET', '/api/config', 'config:read'],
        'GET clients' => ['GET', '/api/clients', 'library:read'],
        'GET clients/{id}/workspace' => ['GET', '/api/clients/'.Fx::MISSING_ID.'/workspace', 'library:read'],
        'GET clients/{id}/bundle' => ['GET', '/api/clients/'.Fx::MISSING_ID.'/bundle', 'library:read'],
        'PUT clients/{id}/brief' => ['PUT', '/api/clients/'.Fx::MISSING_ID.'/brief', 'sessions:write'],
        'POST clients/{id}/brief/refresh' => ['POST', '/api/clients/'.Fx::MISSING_ID.'/brief/refresh', 'sessions:write'],
        'POST clients/{id}/memory' => ['POST', '/api/clients/'.Fx::MISSING_ID.'/memory', 'sessions:write'],
        'PATCH clients/{id}/memory/{item}' => ['PATCH', '/api/clients/'.Fx::MISSING_ID.'/memory/'.Fx::MISSING_ID, 'sessions:write'],
        'DELETE clients/{id}/memory/{item}' => ['DELETE', '/api/clients/'.Fx::MISSING_ID.'/memory/'.Fx::MISSING_ID, 'sessions:write'],
        'POST clients/{id}/files' => ['POST', '/api/clients/'.Fx::MISSING_ID.'/files', 'sessions:write'],
        'DELETE clients/{id}/files/{file}' => ['DELETE', '/api/clients/'.Fx::MISSING_ID.'/files/'.Fx::MISSING_ID, 'sessions:write'],
        'POST clients/{id}/ask' => ['POST', '/api/clients/'.Fx::MISSING_ID.'/ask', 'sessions:write'],
        'GET sessions/{id}/notes' => ['GET', '/api/sessions/'.Fx::MISSING_ID.'/notes', 'library:read'],
        'POST sessions/{id}/notes' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/notes', 'sessions:write'],
        'POST transcripts/{id}/suggestions/{suggestion}' => ['POST', '/api/transcripts/'.Fx::MISSING_ID.'/suggestions/0', 'sessions:write'],
        'POST stt/token' => ['POST', '/api/stt/token', 'stt:token'],
        'POST usage' => ['POST', '/api/usage', 'stt:token'],
        'POST live-translate/token' => ['POST', '/api/live-translate/token', 'stt:token'],
        'POST sessions/{id}/opened' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/opened', 'library:read'],
        'POST sessions' => ['POST', '/api/sessions', 'sessions:write'],
        'POST sessions/{id}/finish' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/finish', 'sessions:write'],
        'POST sessions/{id}/markers' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/markers', 'sessions:write'],
        'POST sessions/{id}/retranscribe' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/retranscribe', 'sessions:write'],
        'DELETE sessions/{id}/markers/{marker}' => ['DELETE', '/api/sessions/'.Fx::MISSING_ID.'/markers/'.Fx::MISSING_ID, 'sessions:write'],
        'PUT sessions/{id}/speakers' => ['PUT', '/api/sessions/'.Fx::MISSING_ID.'/speakers', 'sessions:write'],
        'PATCH transcripts/{id}' => ['PATCH', '/api/transcripts/'.Fx::MISSING_ID, 'sessions:write'],
        'POST transcripts/{id}/replace' => ['POST', '/api/transcripts/'.Fx::MISSING_ID.'/replace', 'sessions:write'],
        'POST transcripts/{id}/restore' => ['POST', '/api/transcripts/'.Fx::MISSING_ID.'/restore', 'sessions:write'],
        'GET transcripts/{id}/revisions' => ['GET', '/api/transcripts/'.Fx::MISSING_ID.'/revisions', 'library:read'],
        'GET search' => ['GET', '/api/search?q=invoice', 'library:read'],
        'GET transcripts/{id}/export' => ['GET', '/api/transcripts/'.Fx::MISSING_ID.'/export?format=txt', 'library:read'],
        'GET transcripts/{id}/views' => ['GET', '/api/transcripts/'.Fx::MISSING_ID.'/views', 'library:read'],
        'POST transcripts/{id}/views' => ['POST', '/api/transcripts/'.Fx::MISSING_ID.'/views', 'sessions:write'],
        'GET sessions/{id}/shares' => ['GET', '/api/sessions/'.Fx::MISSING_ID.'/shares', 'library:read'],
        'POST sessions/{id}/shares' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/shares', 'sessions:write'],
        'DELETE shares/{id}' => ['DELETE', '/api/shares/'.Fx::MISSING_ID, 'sessions:write'],
        'GET vocabulary' => ['GET', '/api/vocabulary', 'library:read'],
        'POST vocabulary/keyterms' => ['POST', '/api/vocabulary/keyterms', 'sessions:write'],
        'POST vocabulary/replacements' => ['POST', '/api/vocabulary/replacements', 'sessions:write'],
        'POST sessions/{id}/recordings' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/recordings', 'sessions:write'],
        'POST sessions/{id}/transcripts' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/transcripts', 'sessions:write'],
        'DELETE sessions/{id}' => ['DELETE', '/api/sessions/'.Fx::MISSING_ID, 'sessions:write'],
        'GET recordings/{id}' => ['GET', '/api/recordings/'.Fx::MISSING_ID, 'recordings:upload'],
        'POST recordings/{id}/chunks' => ['POST', '/api/recordings/'.Fx::MISSING_ID.'/chunks', 'recordings:upload'],
        'POST recordings/{id}/complete' => ['POST', '/api/recordings/'.Fx::MISSING_ID.'/complete', 'recordings:upload'],
        'GET library' => ['GET', '/api/library', 'library:read'],
        'GET sessions/{id}' => ['GET', '/api/sessions/'.Fx::MISSING_ID, 'library:read'],
        'GET transcripts/{id}' => ['GET', '/api/transcripts/'.Fx::MISSING_ID, 'library:read'],
    ];
}

dataset('device-guarded routes', secDeviceGuardedRoutes());

/** The guarded routes that require a specific ability; token rotation is open to any valid token (docs/API.md). */
dataset('ability-gated routes', array_filter(secDeviceGuardedRoutes(), fn (array $route): bool => $route[2] !== null));

/** @return array<string, string> */
function secMalformedTokens(): array
{
    return [
        'empty' => '',
        'literal null' => 'null',
        'literal undefined' => 'undefined',
        'single character' => 'x',
        'prefix only' => 'eld_',
        'id and separator only' => '1|',
        'separator only' => '|',
        'id with bare prefix' => '1|eld_',
        'very long' => str_repeat('A', 20000),
        'sql injection' => "1' OR '1'='1",
        'sql injection after id' => "1|' OR 1=1 --",
        'unicode' => "1|eld_\u{202E}\u{1F4A9}",
        'path traversal' => '../../etc/passwd',
        'script tag' => '<script>alert(1)</script>',
    ];
}

it('exposes exactly the documented public API routes and guards every other one', function () {
    $public = [];
    $guarded = 0;
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }
            in_array('auth:sanctum', $route->gatherMiddleware(), true) ? $guarded++ : $public[] = $method.' '.$route->uri();
        }
    }
    sort($public);

    expect($public)->toBe([
        'GET api/recordings/{recording}/audio',
        'POST api/devices/register',
        'POST api/webhooks/stt/{provider}',
    ]);
    // A new guarded route must be added to the dataset above so it is covered by every case in this file.
    expect($guarded)->toBe(63);
});

it('rejects a missing token with 401 JSON, not a redirect', function (string $method, string $uri) {
    $this->api()->json($method, $uri)->assertUnauthorized();
    $this->api(null, false)->call($method, $uri)->assertUnauthorized();
    $this->api(null, false)->withHeaders(['Accept' => 'text/html'])->call($method, $uri)->assertUnauthorized();
})->with('device-guarded routes');

it('rejects malformed bearer tokens on every guarded route', function (string $method, string $uri) {
    $failures = [];
    foreach (secMalformedTokens() as $label => $token) {
        $status = $this->api()->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $uri)->getStatusCode();
        if ($status !== 401) {
            $failures[] = "{$label} => {$status}";
        }
    }

    expect($failures)->toBe([]);
})->with('device-guarded routes');

it('rejects authorization schemes other than Bearer', function () {
    $plain = Fx::token(Fx::device());

    foreach (['Basic '.base64_encode('user:'.$plain), 'Token '.$plain, 'Digest '.$plain, $plain] as $header) {
        $this->api()->withHeaders(['Authorization' => $header])->getJson('/api/config')->assertUnauthorized();
    }
});

it('does not accept the token from the query string, a cookie or a body field', function () {
    $plain = Fx::token(Fx::device());

    $this->api()->getJson('/api/config?token='.urlencode($plain))->assertUnauthorized();
    $this->api()->getJson('/api/config?api_token='.urlencode($plain))->assertUnauthorized();
    $this->api()->withUnencryptedCookie('token', $plain)->getJson('/api/config')->assertUnauthorized();
    $this->api()->postJson('/api/devices/rotate-token', ['token' => $plain, 'api_token' => $plain])->assertUnauthorized();
});

it('rejects a token whose secret part was altered', function () {
    $plain = Fx::token(Fx::device());
    $flipped = substr($plain, 0, -1).(str_ends_with($plain, 'a') ? 'b' : 'a');

    $this->api($plain)->getJson('/api/config')->assertOk();
    $this->api($flipped)->getJson('/api/config')->assertUnauthorized();
});

it('rejects one token\'s id paired with another token\'s secret', function () {
    $a = Fx::token(Fx::device());
    $b = Fx::token(Fx::device());
    [$idA] = explode('|', $a, 2);
    [, $secretB] = explode('|', $b, 2);

    $this->api($idA.'|'.$secretB)->getJson('/api/config')->assertUnauthorized();
});

it('stores only a hash of the token and never the plain text', function () {
    $plain = Fx::token(Fx::device());
    [, $secret] = explode('|', $plain, 2);

    $row = DB::table('personal_access_tokens')->first();

    expect($row->token)->toBe(hash('sha256', $secret))
        ->and($row->token)->not->toBe($secret)
        ->and(json_encode($row))->not->toContain($secret);
    expect($plain)->toContain('eld_');
});

it('rejects a revoked token immediately on every guarded route', function (string $method, string $uri) {
    $plain = Fx::token(Fx::device());
    $this->api($plain)->json('GET', '/api/config')->assertOk();

    PersonalAccessToken::findToken($plain)->delete();

    $this->api($plain)->json($method, $uri)->assertUnauthorized();
})->with('device-guarded routes');

it('rejects an expired token on every guarded route', function (string $method, string $uri) {
    $plain = Fx::token(Fx::device(), null, now()->subSecond());

    $this->api($plain)->json($method, $uri)->assertUnauthorized();
})->with('device-guarded routes');

it('stops honouring a token the moment its expiry passes', function () {
    $plain = Fx::token(Fx::device(), null, now()->addMinutes(10));
    $this->api($plain)->getJson('/api/config')->assertOk();

    $this->travel(11)->minutes();

    $this->api($plain)->getJson('/api/config')->assertUnauthorized();
});

it('denies a token that lacks the ability a route requires', function (string $method, string $uri, string $ability) {
    $plain = Fx::token(Fx::device(), array_values(array_diff(Device::ABILITIES, [$ability])));

    $this->api($plain)->json($method, $uri)->assertForbidden();
})->with('ability-gated routes');

it('lets a token holding only the required ability past the ability check', function (string $method, string $uri, string $ability) {
    $plain = Fx::token(Fx::device(), [$ability]);

    $status = $this->api($plain)->json($method, $uri)->getStatusCode();

    expect($status)->not->toBe(401)->and($status)->not->toBe(403);
})->with('ability-gated routes');

it('denies a token with no abilities on every ability-gated route', function (string $method, string $uri, string $ability) {
    $plain = Fx::token(Fx::device(), []);

    $this->api($plain)->json($method, $uri)->assertForbidden();
})->with('ability-gated routes');

it('stops a device token the moment its device is removed', function () {
    $device = Fx::device();
    $plain = Fx::token($device);
    $this->api($plain)->getJson('/api/config')->assertOk();

    $device->delete();

    $this->api($plain)->getJson('/api/config')->assertUnauthorized();
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('rejects an orphaned token whose device row vanished without the model event', function () {
    $device = Fx::device();
    $plain = Fx::token($device);
    DB::table('devices')->where('id', $device->id)->delete();

    $this->api($plain)->getJson('/api/config')->assertUnauthorized();
});

it('stops device tokens when the owning user is deleted', function () {
    $user = Fx::user();
    $plain = Fx::token(Fx::device($user));
    $this->api($plain)->getJson('/api/config')->assertOk();

    $user->delete();

    $this->api($plain)->getJson('/api/config')->assertUnauthorized();
});

it('does not let an admin web session satisfy the device guard', function () {
    $this->actingAs(Fx::superAdmin(), 'web');

    $this->api()->getJson('/api/config')->assertUnauthorized();
    $this->api()->getJson('/api/library')->assertUnauthorized();
    $this->api()->postJson('/api/sessions', Fx::sessionPayload())->assertUnauthorized();
});

it('does not let a device token open the admin panel or the admin media route', function () {
    $plain = Fx::token(Fx::device());

    $this->api($plain, false)->get('/admin')->assertRedirect();
    $this->api($plain, false)->get('/admin-media/recordings/'.Fx::MISSING_ID)->assertRedirect();
});

it('revokes the old token as soon as it is rotated and issues one that works', function () {
    $old = Fx::token(Fx::device());

    $new = $this->api($old)->postJson('/api/devices/rotate-token')->assertOk()->json('token');

    expect($new)->not->toBe($old);
    $this->api($old)->getJson('/api/config')->assertUnauthorized();
    $this->api($new)->getJson('/api/config')->assertOk();
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('gives a rotated token a bounded lifetime', function () {
    $response = $this->api(Fx::token(Fx::device()))->postJson('/api/devices/rotate-token')->assertOk();

    $expires = Carbon::parse($response->json('token_expires_at'));

    expect($expires->isFuture())->toBeTrue()
        ->and($expires->lessThanOrEqualTo(now()->addDays(366)))->toBeTrue();
});

it('keeps a rotated token no more privileged than the token that asked for it', function () {
    $limited = Fx::token(Fx::device(), ['library:read']);

    $new = $this->api($limited)->postJson('/api/devices/rotate-token')->assertOk()->json('token');

    // The replacement must not be able to do anything the limited token could not.
    $this->api($new)->postJson('/api/sessions', Fx::sessionPayload())->assertForbidden();
});

it('lets one device rotate its own token without touching a sibling device\'s token', function () {
    $user = Fx::user();
    $tokenA = Fx::token(Fx::device($user));
    $tokenB = Fx::token(Fx::device($user));

    $this->api($tokenA)->postJson('/api/devices/rotate-token')->assertOk();

    $this->api($tokenB)->getJson('/api/config')->assertOk();
});
