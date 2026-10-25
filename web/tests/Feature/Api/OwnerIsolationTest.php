<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Two owners, each with a device. The victim has a finished recording, a transcript and a client with keyterms; the
 * attacker holds a fully privileged token of their own. Nothing the attacker sends may reveal or change the victim's data.
 */
beforeEach(function () {
    $this->victim = User::factory()->create();
    [$this->victimDevice, $this->victimToken] = $this->enroll($this->victim);
    $this->asToken($this->victimToken);
    $this->flow = $this->uploadRecording(Fixtures::audio(4096, 'VICTIM-AUDIO'), ['client_ref' => 'victim-ref', 'title' => 'Victim private call']);
    $this->session = $this->flow['session'];
    $this->recording = $this->flow['recording'];
    $this->transcript = $this->postJson("/api/sessions/{$this->session}/transcripts", [
        'kind' => 'live', 'provider' => 'assemblyai', 'segments' => [Fixtures::segment(['text' => 'victim secret words'])],
    ])->assertCreated()->json('id');
    $this->client = Client::factory()->create(['user_id' => $this->victim->id, 'name' => 'Victim Client Ltd']);
    ClientKeyterm::query()->create(['client_id' => $this->client->id, 'term' => 'Project Nightingale']);
    $this->playbackUrl = $this->getJson("/api/sessions/{$this->session}")->json('playback_url');

    [$this->attackerDevice, $this->attackerToken] = $this->enroll();
    $this->asToken($this->attackerToken);
    $this->path = (string) Recording::query()->find($this->recording)->stored_path;
});

/** Everything the attacker must never see. */
function victimMarkers(): array
{
    return ['Victim', 'victim secret words', 'Project Nightingale', 'VICTIM-AUDIO', 'victim-ref'];
}

describe('reading', function () {
    it('answers 404 for each of the victim\'s resources and reveals nothing', function (string $uri) {
        $response = $this->getJson(str_replace(['{s}', '{r}', '{t}'], [$this->session, $this->recording, $this->transcript], $uri));

        $response->assertNotFound();
        foreach (victimMarkers() as $marker) {
            expect($response->getContent())->not->toContain($marker);
        }
    })->with(['/api/sessions/{s}', '/api/recordings/{r}', '/api/transcripts/{t}']);

    it('shows an empty library and an empty client list', function () {
        expect($this->getJson('/api/library')->assertOk()->json('data'))->toBe([]);
        expect($this->getJson('/api/clients')->assertOk()->json('data'))->toBe([]);
        $incremental = $this->getJson('/api/library?since='.urlencode(now()->subYear()->toAtomString()))->assertOk();
        expect($incremental->json('data'))->toBe([]);
    });

    it('cannot filter its way to the victim\'s data', function () {
        foreach (['source=phone', 'source_app=whatsapp', 'status=uploading', 'status=completed', 'client_id='.$this->client->id] as $query) {
            expect($this->getJson("/api/library?{$query}")->assertOk()->json('data'))->toBe([]);
        }
    });

    it('cannot learn the victim\'s keyterms through a streaming token', function () {
        $this->configureAssemblyAi();

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $this->client->id]);

        $response->assertNotFound();
        expect($response->getContent())->not->toContain('Nightingale');
    });

    it('gets the same answer for a victim\'s id as for an id that never existed', function () {
        $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $pairs = [
            ['GET', "/api/sessions/{$this->session}", "/api/sessions/{$missing}"],
            ['GET', "/api/transcripts/{$this->transcript}", "/api/transcripts/{$missing}"],
            ['GET', "/api/recordings/{$this->recording}", "/api/recordings/{$missing}"],
            ['POST', "/api/recordings/{$this->recording}/complete", "/api/recordings/{$missing}/complete"],
            ['DELETE', "/api/sessions/{$this->session}", "/api/sessions/{$missing}"],
        ];

        foreach ($pairs as [$method, $real, $fake]) {
            $a = $this->json($method, $real);
            $b = $this->json($method, $fake);
            expect($a->getStatusCode())->toBe($b->getStatusCode())->toBe(404);
            expect(str_replace([$this->session, $this->recording, $this->transcript], '<id>', $a->getContent()))->toBe(str_replace($missing, '<id>', $b->getContent()));
        }
    });

    it('keeps internal class names out of error messages', function () {
        $body = $this->getJson("/api/sessions/{$this->session}")->assertNotFound()->getContent();

        expect($body)->not->toContain('App\\\\Models')->not->toContain('CaptureSession');
    })->skip('A6.W17: 404 responses name internal model classes');

    it('does not accept malformed ids as a way to probe', function (string $id) {
        foreach (["/api/sessions/{$id}", "/api/recordings/{$id}", "/api/transcripts/{$id}"] as $uri) {
            expect($this->getJson($uri)->getStatusCode())->toBeIn([404]);
        }
    })->with([
        'sql' => ["'%20OR%201=1--"],
        'wildcard' => ['%25'],
        'traversal' => ['..%2F..%2Fetc%2Fpasswd'],
        'too long' => [str_repeat('A', 300)],
        'unicode' => ['%E2%98%83'],
        'null byte' => ['%00'],
        'prefix of a real id' => ['01ARZ'],
    ]);
});

describe('changing', function () {
    it('cannot delete the victim\'s session', function () {
        $this->deleteJson("/api/sessions/{$this->session}")->assertNotFound();

        expect(CaptureSession::query()->find($this->session))->not->toBeNull()->and(Storage::disk('recordings')->exists($this->path))->toBeTrue();
    });

    it('cannot push chunks into the victim\'s upload or complete it', function () {
        $before = Storage::disk('recordings')->get($this->path);

        $this->postChunk($this->recording, 0, str_repeat('x', 4096))->assertNotFound();
        $this->postJson("/api/recordings/{$this->recording}/complete")->assertNotFound();

        expect(Storage::disk('recordings')->get($this->path))->toBe($before);
    });

    it('cannot attach recordings or transcripts to the victim\'s session', function () {
        $this->postJson("/api/sessions/{$this->session}/recordings", Fixtures::artifact('abc', ['role' => 'channel_1']))->assertNotFound();
        $this->postJson("/api/sessions/{$this->session}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => [Fixtures::segment(['text' => 'overwritten'])]])->assertNotFound();

        expect(Transcript::query()->find($this->transcript)->text)->toContain('victim secret words')
            ->and(Recording::query()->where('capture_session_id', $this->session)->count())->toBe(1);
    });

    it('cannot file a new session under the victim\'s client', function () {
        $response = $this->createSession(Fixtures::audio(2000), ['client_id' => $this->client->id]);

        expect($response->getStatusCode())->toBeIn([404, 422])->and(CaptureSession::query()->where('client_id', $this->client->id)->count())->toBe(0);
    });

    it('cannot replay the victim\'s client_ref to read their session back', function () {
        $response = $this->createSession(Fixtures::audio(4096), ['client_ref' => 'victim-ref'])->assertCreated();

        expect($response->json('session.id'))->not->toBe($this->session)->and($response->json('session.title'))->not->toBe('Victim private call');
    });
});

describe('the playback capability', function () {
    it('is a bearer capability: whoever holds the URL can play it, which is why it expires and is never listed', function () {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->get($this->playbackUrl)->assertOk();
    });

    it('is not derivable from another recording\'s URL', function () {
        $mine = $this->asToken($this->attackerToken)->uploadRecording(Fixtures::audio(3000, 'ATTACKER'), ['client_ref' => 'mine']);
        $myUrl = $this->getJson("/api/sessions/{$mine['session']}")->json('playback_url');
        $forged = str_replace($mine['recording'], $this->recording, $myUrl);
        $this->flushHeaders();

        $this->get($forged)->assertForbidden();
    });
});

describe('between the owner\'s own devices', function () {
    it('shares sessions, transcripts and uploads, as a single library should', function () {
        [, $laptop] = $this->enroll($this->victim, platform: 'windows');

        $this->asToken($laptop)->getJson("/api/sessions/{$this->session}")->assertOk();
        $this->getJson("/api/transcripts/{$this->transcript}")->assertOk();
        $this->getJson("/api/recordings/{$this->recording}")->assertOk();
        expect(array_column($this->getJson('/api/library')->json('data'), 'id'))->toContain($this->session);
    });
});
