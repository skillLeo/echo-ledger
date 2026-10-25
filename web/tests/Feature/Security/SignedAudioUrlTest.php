<?php

declare(strict_types=1);

use App\Models\Recording;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

beforeEach(function () {
    Fx::fakeRecordingsDisk();
});

/** @return array{0: string, 1: Recording, 2: string, 3: string} playback url, recording, plain bytes, owner token */
function secPlayback(): array
{
    $owner = Fx::user();
    $device = Fx::device($owner);
    $session = Fx::session($owner, $device);
    $bytes = random_bytes(4096);
    $recording = Fx::readyRecording($session, $bytes);
    $token = Fx::token($device);

    $url = test()->api($token)->getJson("/api/sessions/{$session->id}")->assertOk()->json('playback_url');

    return [$url, $recording, $bytes, $token];
}

/** @return array<string, string> */
function secQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

function secWithQuery(string $url, array $query): string
{
    return strtok($url, '?').'?'.http_build_query($query);
}

it('serves the decrypted bytes from a valid signed URL without a bearer token', function () {
    [$url, , $bytes] = secPlayback();

    $response = $this->api(null, false)->get($url);

    $response->assertOk();
    ob_start();
    $response->baseResponse->sendContent();
    expect(ob_get_clean())->toBe($bytes);
});

it('expires a playback link about thirty minutes after issue', function () {
    [$url] = secPlayback();

    $expires = (int) secQuery($url)['expires'];

    expect($expires)->toBeGreaterThan(now()->addMinutes(29)->timestamp)->toBeLessThanOrEqual(now()->addMinutes(31)->timestamp);
});

it('rejects a tampered signature', function () {
    [$url] = secPlayback();
    $query = secQuery($url);
    $query['signature'] = substr($query['signature'], 0, -1).(str_ends_with($query['signature'], 'a') ? 'b' : 'a');

    $this->api(null, false)->get(secWithQuery($url, $query))->assertForbidden();
});

it('rejects a link whose expiry was extended without re-signing', function () {
    [$url] = secPlayback();
    $query = secQuery($url);
    $query['expires'] = (string) (((int) $query['expires']) + 86400 * 365);

    $this->api(null, false)->get(secWithQuery($url, $query))->assertForbidden();
});

it('rejects a link with the signature or the expiry removed', function () {
    [$url] = secPlayback();
    $query = secQuery($url);

    $this->api(null, false)->get(secWithQuery($url, array_diff_key($query, ['signature' => 1])))->assertForbidden();
    $this->api(null, false)->get(secWithQuery($url, array_diff_key($query, ['expires' => 1])))->assertForbidden();
    $this->api(null, false)->get(strtok($url, '?'))->assertForbidden();
});

it('rejects a link with an extra query parameter appended', function () {
    [$url] = secPlayback();

    $this->api(null, false)->get($url.'&download=1')->assertForbidden();
    $this->api(null, false)->get($url.'&recording=other')->assertForbidden();
});

it('rejects a signature reused on a different recording id', function () {
    [$url, $recording] = secPlayback();
    $other = Fx::readyRecording(Fx::session(Fx::user()), random_bytes(512));

    $this->api(null, false)->get(str_replace($recording->id, $other->id, $url))->assertForbidden();
});

it('rejects a signature produced for another host', function () {
    [$url] = secPlayback();
    $host = parse_url($url, PHP_URL_HOST);

    $this->api(null, false)->get(str_replace($host, 'attacker.example', $url))->assertForbidden();
});

it('rejects an expired link', function () {
    [$url] = secPlayback();

    $this->travel(31)->minutes();

    $this->api(null, false)->get($url)->assertForbidden();
});

it('accepts the link right up to the expiry boundary and not after', function () {
    $session = Fx::session(Fx::user());
    $recording = Fx::readyRecording($session, 'x');
    $url = URL::temporarySignedRoute('api.recordings.audio', now()->addSeconds(60), ['recording' => $recording->id]);

    $this->travel(59)->seconds();
    $this->api(null, false)->get($url)->assertOk();

    $this->travel(2)->seconds();
    $this->api(null, false)->get($url)->assertForbidden();
});

it('stops serving a link as soon as the session is deleted, and the file is erased when the undo bin ends', function () {
    [$url, $recording, , $token] = secPlayback();
    $this->api(null, false)->get($url)->assertOk();
    $path = $recording->stored_path;

    $this->api($token)->deleteJson('/api/sessions/'.$recording->capture_session_id)->assertNoContent();

    $this->api(null, false)->get($url)->assertNotFound();
    Storage::disk('recordings')->assertExists($path);
    emptyUndoBin();
    Storage::disk('recordings')->assertMissing($path);
});

it('does not serve a recording that is not ready even with a valid signature', function () {
    $session = Fx::session(Fx::user());
    $pending = Fx::pendingRecording($session, str_repeat('p', 2048), 1024);
    $url = URL::temporarySignedRoute('api.recordings.audio', now()->addMinutes(5), ['recording' => $pending->id]);

    $this->api(null, false)->get($url)->assertNotFound();
});

it('does not hand out a playback link for another owner\'s session', function () {
    $alice = Fx::token(Fx::device());
    $bobSession = Fx::session(Fx::user());
    Fx::readyRecording($bobSession);

    $response = $this->api($alice)->getJson("/api/sessions/{$bobSession->id}");

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('signature=');
});

it('only accepts GET on the audio route', function () {
    [$url] = secPlayback();

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $this->api(null, false)->call($method, $url)->assertStatus(405);
    }
});

it('sends private no-store caching and nosniff with the audio', function () {
    [$url] = secPlayback();

    $response = $this->api(null, false)->get($url)->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toBe('audio/ogg');
});

it('answers valid range requests with 206 and the matching Content-Range', function () {
    [$url, , $bytes] = secPlayback();

    $partial = $this->api(null, false)->withHeaders(['Range' => 'bytes=10-19'])->get($url);
    $partial->assertStatus(206);
    expect($partial->headers->get('Content-Range'))->toBe('bytes 10-19/4096');
    ob_start();
    $partial->baseResponse->sendContent();
    expect(ob_get_clean())->toBe(substr($bytes, 10, 10));

    $suffix = $this->api(null, false)->withHeaders(['Range' => 'bytes=-16'])->get($url);
    $suffix->assertStatus(206);
    expect($suffix->headers->get('Content-Range'))->toBe('bytes 4080-4095/4096');
});

it('answers a range that starts past the end of the file with 416 as documented', function () {
    [$url] = secPlayback();

    $this->api(null, false)->withHeaders(['Range' => 'bytes=999999-'])->get($url)->assertStatus(416);
});

it('never fails with a server error on malformed range headers', function () {
    [$url] = secPlayback();
    $failures = [];

    foreach (['bytes=abc', 'bytes=5-1', 'bytes=-', 'bytes=0-1,3-4', 'items=0-5', 'bytes=0-99999999999999999999', 'bytes=-0', 'bytes=--5', 'bytes=1-2-3', str_repeat('bytes=0-1,', 500)] as $range) {
        $status = $this->api(null, false)->withHeaders(['Range' => $range])->get($url)->getStatusCode();
        if ($status >= 500) {
            $failures[] = "{$range} => {$status}";
        }
    }

    expect($failures)->toBe([]);
});
it('builds playback links for the configured application host, not the Host header', function () {
    $owner = Fx::user();
    $device = Fx::device($owner);
    $session = Fx::session($owner, $device);
    Fx::readyRecording($session);

    $response = $this->api(Fx::token($device))
        ->withServerVariables(['HTTP_HOST' => 'attacker.example'])
        ->getJson("/api/sessions/{$session->id}");

    $response->assertOk();
    expect(parse_url((string) $response->json('playback_url'), PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));
});
