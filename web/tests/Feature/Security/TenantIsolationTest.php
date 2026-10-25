<?php

declare(strict_types=1);

use App\Enums\DevicePlatform;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

beforeEach(function () {
    Fx::fakeRecordingsDisk();
});

/**
 * Two separate owners. Bob has a finished call (encrypted audio plus transcript), an upload in progress and a client.
 * Alice only holds a device token.
 *
 * @return array<string, mixed>
 */
function secTenants(): array
{
    $alice = Fx::user();
    $bob = Fx::user();
    $aliceDevice = Fx::device($alice);
    $bobDevice = Fx::device($bob);

    $bobSession = Fx::session($bob, $bobDevice, ['title' => 'Bob confidential call']);
    $bobAudio = Fx::readyRecording($bobSession, 'BOB-SECRET-AUDIO-'.random_bytes(200));
    $bobTranscript = Fx::transcript($bobSession, 'BOB secret transcript sentence');

    $bobUploadSession = Fx::session($bob, $bobDevice, ['title' => 'Bob upload in progress']);
    $bobUpload = Fx::pendingRecording($bobUploadSession, str_repeat('b', 2048), 1024);

    return [
        'alice' => $alice, 'bob' => $bob,
        'aliceToken' => Fx::token($aliceDevice), 'bobToken' => Fx::token($bobDevice),
        'bobDevice' => $bobDevice,
        'bobSession' => $bobSession, 'bobAudio' => $bobAudio, 'bobTranscript' => $bobTranscript,
        'bobUploadSession' => $bobUploadSession, 'bobUpload' => $bobUpload,
        'bobClient' => Fx::client($bob, 'Bob Private Client'),
    ];
}

it('hides another owner\'s session from read, delete and every write route', function () {
    $t = secTenants();
    $sid = $t['bobSession']->id;

    $this->api($t['aliceToken'])->getJson("/api/sessions/{$sid}")->assertNotFound();
    $this->api($t['aliceToken'])->deleteJson("/api/sessions/{$sid}")->assertNotFound();
    $this->api($t['aliceToken'])->postJson("/api/sessions/{$sid}/transcripts", ['kind' => 'live', 'provider' => 'x', 'segments' => Fx::segments('HIJACK')])->assertNotFound();
    $this->api($t['aliceToken'])->postJson("/api/sessions/{$sid}/recordings", Fx::artifact(['role' => 'channel_1']))->assertNotFound();

    expect(CaptureSession::query()->find($sid))->not->toBeNull()
        ->and(Recording::query()->where('capture_session_id', $sid)->count())->toBe(1)
        ->and(Transcript::query()->where('capture_session_id', $sid)->value('text'))->toBe('BOB secret transcript sentence');
    Storage::disk('recordings')->assertExists($t['bobAudio']->stored_path);
});

it('hides another owner\'s recordings from upload state, chunks and completion', function () {
    $t = secTenants();
    $rid = $t['bobUpload']->id;
    $chunk = str_repeat('b', 1024);

    $this->api($t['aliceToken'])->getJson("/api/recordings/{$rid}")->assertNotFound();
    $this->putChunk($t['aliceToken'], $rid, 0, $chunk)->assertNotFound();
    $this->api($t['aliceToken'])->postJson("/api/recordings/{$rid}/complete")->assertNotFound();

    expect($t['bobUpload']->fresh()->received_chunks)->toBe([])
        ->and(Storage::disk('recordings')->exists($t['bobUpload']->partsDirectory().'/0.part'))->toBeFalse();
});

it('hides another owner\'s transcript text', function () {
    $t = secTenants();

    $response = $this->api($t['aliceToken'])->getJson('/api/transcripts/'.$t['bobTranscript']->id);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('BOB secret');
});

it('does not leak another owner\'s library items, clients or tombstones', function () {
    $t = secTenants();
    $deleted = Fx::session($t['bob'], $t['bobDevice'], ['title' => 'Bob deleted call']);
    $deleted->delete();

    $library = $this->api($t['aliceToken'])->getJson('/api/library?since=1970-01-01T00:00:00Z&limit=100')->assertOk();
    expect($library->json('data'))->toBe([]);
    expect($library->getContent())->not->toContain($t['bobSession']->id)->not->toContain($deleted->id)->not->toContain('Bob');

    $clients = $this->api($t['aliceToken'])->getJson('/api/clients')->assertOk();
    expect($clients->json('data'))->toBe([]);

    // Positive control: Bob does see his own data through the same endpoints.
    $bobLibrary = $this->api($t['bobToken'])->getJson('/api/library?since=1970-01-01T00:00:00Z&limit=100')->assertOk();
    expect(collect($bobLibrary->json('data'))->pluck('id')->all())->toContain($t['bobSession']->id, $deleted->id);
});

it('does not honour another owner\'s client id when creating a session or minting streaming tokens', function () {
    $t = secTenants();
    $before = CaptureSession::query()->count();

    $this->api($t['aliceToken'])->postJson('/api/sessions', Fx::sessionPayload(['client_id' => $t['bobClient']->id]))->assertNotFound();
    $this->api($t['aliceToken'])->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $t['bobClient']->id])->assertNotFound();
    $this->api($t['aliceToken'])->getJson('/api/library?client_id='.$t['bobClient']->id)->assertOk()->assertJsonPath('data', []);

    expect(CaptureSession::query()->count())->toBe($before);
});

it('scopes idempotent session creation to the calling device, not to the whole server', function () {
    $t = secTenants();
    $ref = $t['bobSession']->client_ref;

    $response = $this->api($t['aliceToken'])->postJson('/api/sessions', Fx::sessionPayload(['client_ref' => $ref]));

    $response->assertCreated();
    expect($response->json('session.id'))->not->toBe($t['bobSession']->id);
    expect($response->getContent())->not->toContain('Bob confidential call');
    expect(CaptureSession::query()->find($response->json('session.id'))->user_id)->toBe($t['alice']->id);
});

it('answers a foreign id and a missing id identically so ids cannot be probed', function (string $method, string $path) {
    config(['app.debug' => false]);
    $t = secTenants();
    $foreign = str_replace('{id}', $t['bobSession']->id, $path);
    $missing = str_replace('{id}', Fx::MISSING_ID, $path);

    $a = $this->api($t['aliceToken'])->json($method, $foreign);
    $b = $this->api($t['aliceToken'])->json($method, $missing);

    $normalise = fn (string $body, string $id): string => str_replace($id, '<id>', $body);
    expect($a->getStatusCode())->toBe($b->getStatusCode())
        ->and($normalise($a->getContent(), $t['bobSession']->id))->toBe($normalise($b->getContent(), Fx::MISSING_ID));
})->with([
    'show session' => ['GET', '/api/sessions/{id}'],
    'delete session' => ['DELETE', '/api/sessions/{id}'],
    'add transcript' => ['POST', '/api/sessions/{id}/transcripts'],
    'add recording' => ['POST', '/api/sessions/{id}/recordings'],
]);

it('does not reveal internal class names in not-found responses', function () {
    $t = secTenants();
    config(['app.debug' => false]);

    $body = $this->api($t['aliceToken'])->getJson('/api/sessions/'.$t['bobSession']->id)->assertNotFound()->getContent();

    expect($body)->not->toContain('App\\\\Models')->not->toContain('App\\Models')->not->toContain($t['bobSession']->id);
})->skip('A6.W17: 404 responses name internal model classes');

it('lets a second device of the same owner share the library as documented', function () {
    $owner = Fx::user();
    $phone = Fx::device($owner);
    $laptop = Fx::device($owner, ['platform' => DevicePlatform::Windows]);
    $session = Fx::session($owner, $phone);
    Fx::readyRecording($session);
    $laptopToken = Fx::token($laptop);

    $this->api($laptopToken)->getJson("/api/sessions/{$session->id}")->assertOk();
    $this->api($laptopToken)->getJson('/api/library')->assertOk()->assertJsonPath('data.0.id', $session->id);
    $this->api($laptopToken)->deleteJson("/api/sessions/{$session->id}")->assertNoContent();
});

it('treats a deleted session as gone for every route, including for its owner', function () {
    $t = secTenants();
    $sid = $t['bobSession']->id;
    $this->api($t['bobToken'])->deleteJson("/api/sessions/{$sid}")->assertNoContent();

    $this->api($t['bobToken'])->getJson("/api/sessions/{$sid}")->assertNotFound();
    $this->api($t['bobToken'])->getJson('/api/transcripts/'.$t['bobTranscript']->id)->assertNotFound();
    $this->api($t['bobToken'])->getJson('/api/recordings/'.$t['bobAudio']->id)->assertNotFound();
    $this->api($t['bobToken'])->postJson("/api/sessions/{$sid}/transcripts", ['kind' => 'live', 'provider' => 'x', 'segments' => Fx::segments('late')])->assertNotFound();
    $this->api($t['bobToken'])->deleteJson("/api/sessions/{$sid}")->assertNotFound();

    expect(Recording::query()->where('capture_session_id', $sid)->count())->toBe(0)
        ->and(Transcript::query()->where('capture_session_id', $sid)->count())->toBe(0);
});

it('erases the encrypted file from disk when a session is deleted', function () {
    $t = secTenants();
    $path = $t['bobAudio']->stored_path;
    Storage::disk('recordings')->assertExists($path);

    $this->api($t['bobToken'])->deleteJson('/api/sessions/'.$t['bobSession']->id)->assertNoContent();

    Storage::disk('recordings')->assertMissing($path);
});

it('answers hostile identifiers on every id route with 404 and never a server error', function (string $method, string $route) {
    $t = secTenants();
    $ids = ['0', '-1', 'null', '../../etc/passwd', '..%2f..%2fetc%2fpasswd', "1' OR '1'='1", '1;DROP TABLE users', '%00', '%', '_', '*',
        str_repeat('A', 5000), '01JZZZZZZZZZZZZZZZZZZZZZZZ%20', 'ǖ', '{{7*7}}', '${jndi:ldap://x}'];
    $failures = [];

    foreach ($ids as $id) {
        $uri = str_replace('{id}', $id, $route);
        $status = $this->api($t['aliceToken'])->json($method, $uri)->getStatusCode();
        if (! in_array($status, [404, 405, 422, 414], true)) {
            $failures[] = "{$method} {$id} => {$status}";
        }
    }

    expect($failures)->toBe([]);
})->with([
    'GET sessions/{id}' => ['GET', '/api/sessions/{id}'],
    'DELETE sessions/{id}' => ['DELETE', '/api/sessions/{id}'],
    'POST sessions/{id}/transcripts' => ['POST', '/api/sessions/{id}/transcripts'],
    'POST sessions/{id}/recordings' => ['POST', '/api/sessions/{id}/recordings'],
    'GET transcripts/{id}' => ['GET', '/api/transcripts/{id}'],
    'GET recordings/{id}' => ['GET', '/api/recordings/{id}'],
    'POST recordings/{id}/complete' => ['POST', '/api/recordings/{id}/complete'],
]);
