<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\SettingsRepository;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->asToken($token);
    $this->session = CaptureSession::factory()->create([
        'user_id' => $this->device->user_id,
        'device_id' => $this->device->id,
        'duration_ms' => 60000,
        'status' => SessionStatus::Uploading,
    ]);
    $this->url = "/api/sessions/{$this->session->id}/transcripts";
});

/** @return array<string, mixed> */
function liveTranscript(array $segments = [], array $overrides = []): array
{
    return array_merge(['kind' => 'live', 'provider' => 'assemblyai', 'language' => 'en', 'segments' => $segments === [] ? [Fixtures::segment()] : $segments], $overrides);
}

describe('live transcripts', function () {
    it('stores the captions and returns the transcript in the documented shape', function () {
        $response = $this->postJson($this->url, liveTranscript([
            Fixtures::segment(['channel' => 2, 'speaker' => 'Client', 'start_ms' => 3000, 'end_ms' => 5000, 'text' => 'Good morning.']),
            Fixtures::segment(['channel' => 1, 'speaker' => 'Me', 'start_ms' => 250, 'end_ms' => 2600, 'text' => 'Smoke from the north.']),
        ]));

        $response->assertCreated()
            ->assertJsonPath('session_id', $this->session->id)
            ->assertJsonPath('kind', 'live')
            ->assertJsonPath('provider', 'assemblyai')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('error', null)
            ->assertJsonStructure(['id', 'text', 'segments', 'created_at', 'completed_at']);
        expect($response->json('text'))->toBe("Me: Smoke from the north.\nClient: Good morning.");
    });

    it('sorts segments by start time and keeps their words', function () {
        $response = $this->postJson($this->url, liveTranscript([
            Fixtures::segment(['start_ms' => 5000, 'end_ms' => 6000, 'text' => 'third']),
            Fixtures::segment(['start_ms' => 1000, 'end_ms' => 2000, 'text' => 'first']),
            Fixtures::segment(['start_ms' => 3000, 'end_ms' => 4000, 'text' => 'second']),
        ]))->assertCreated();

        expect(array_column($response->json('segments'), 'text'))->toBe(['first', 'second', 'third'])
            ->and($response->json('segments.0.words.0'))->toBe(['text' => 'Smoke', 'start_ms' => 250, 'end_ms' => 650, 'confidence' => 0.97]);
    });

    it('does not mark the session as completed, since only the final transcript does that', function () {
        $this->postJson($this->url, liveTranscript())->assertCreated();

        expect($this->session->refresh()->status)->toBe(SessionStatus::Uploading);
    });

    it('is visible in the library item as an available live transcript', function () {
        $id = $this->postJson($this->url, liveTranscript())->assertCreated()->json('id');

        $item = collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $this->session->id);
        expect($item['transcript']['live_available'])->toBeTrue()
            ->and($item['transcript']['live_transcript_id'])->toBe($id)
            ->and($item['transcript']['final_status'])->toBeNull()
            ->and($item['transcript']['preview'])->toBe('Me: Smoke from the north.');
    });

    it('replaces the previous live transcript instead of adding a second one', function () {
        $first = $this->postJson($this->url, liveTranscript([Fixtures::segment(['text' => 'draft one'])]))->assertCreated();
        $second = $this->postJson($this->url, liveTranscript([Fixtures::segment(['text' => 'draft two'])]))->assertCreated();

        expect($second->json('id'))->toBe($first->json('id'))
            ->and(Transcript::query()->where('kind', 'live')->count())->toBe(1)
            ->and($second->json('text'))->toBe('Me: draft two');
    });

    it('labels speakers by channel when the device omits the name', function () {
        $stereo = $this->postJson($this->url, liveTranscript([
            ['channel' => 1, 'start_ms' => 0, 'end_ms' => 500, 'text' => 'a'],
            ['channel' => 2, 'start_ms' => 600, 'end_ms' => 900, 'text' => 'b'],
        ]))->assertCreated();
        expect(array_column($stereo->json('segments'), 'speaker'))->toBe(['Me', 'Client']);

        $mono = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'duration_ms' => 10000]);
        $response = $this->postJson("/api/sessions/{$mono->id}/transcripts", liveTranscript([['channel' => 1, 'start_ms' => 0, 'end_ms' => 500, 'text' => 'only me']]))->assertCreated();
        expect($response->json('segments.0.speaker'))->toBe('Speaker');
    });

    it('accepts an empty list of segments', function () {
        $this->postJson($this->url, ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => []])
            ->assertCreated()->assertJsonPath('segments', [])->assertJsonPath('text', '');
    });

    it('accepts unicode text and keeps it intact', function () {
        $text = 'مرحبا — こんにちは — Привет 👋';

        $this->postJson($this->url, liveTranscript([Fixtures::segment(['text' => $text])]))
            ->assertCreated()->assertJsonPath('segments.0.text', $text);
    });

    it('accepts a segment that ends exactly 500 ms past the session duration and no further', function () {
        $this->postJson($this->url, liveTranscript([Fixtures::segment(['start_ms' => 59000, 'end_ms' => 60500])]))->assertCreated();
        $this->postJson($this->url, liveTranscript([Fixtures::segment(['start_ms' => 59000, 'end_ms' => 60501])]))
            ->assertStatus(422)->assertJsonValidationErrors(['segments.0.end_ms']);
    });

    it('applies no upper bound when the session has no duration', function () {
        $this->session->update(['duration_ms' => null]);

        $this->postJson($this->url, liveTranscript([Fixtures::segment(['start_ms' => 0, 'end_ms' => 99_000_000])]))->assertCreated();
    });

    it('does not change the stored transcript when a later upload is rejected', function () {
        $this->postJson($this->url, liveTranscript([Fixtures::segment(['text' => 'keep me'])]))->assertCreated();

        $this->postJson($this->url, liveTranscript([Fixtures::segment(['channel' => 3])]))->assertStatus(422);

        expect(Transcript::query()->sole()->text)->toBe('Me: keep me');
    });
});

describe('validation', function () {
    it('requires kind, provider and the segments key', function (string $field) {
        $body = liveTranscript();
        unset($body[$field]);

        $this->postJson($this->url, $body)->assertStatus(422)->assertJsonValidationErrors([$field]);
    })->with(['kind', 'provider', 'segments']);

    it('rejects out-of-range or malformed values', function (array $override, string $field) {
        $this->postJson($this->url, liveTranscript([], $override))->assertStatus(422)->assertJsonValidationErrors([$field]);
        expect(Transcript::query()->count())->toBe(0);
    })->with([
        'unknown kind' => [['kind' => 'draft'], 'kind'],
        'kind case' => [['kind' => 'Live'], 'kind'],
        'provider too long' => [['provider' => str_repeat('p', 33)], 'provider'],
        'language too long' => [['language' => str_repeat('l', 17)], 'language'],
        'segments not a list' => [['segments' => 'hello'], 'segments'],
    ]);

    it('rejects a malformed segment and points at it', function (array $segment, string $field) {
        $this->postJson($this->url, liveTranscript([Fixtures::segment($segment)]))
            ->assertStatus(422)->assertJsonValidationErrors(["segments.0.{$field}"]);
        expect(Transcript::query()->count())->toBe(0);
    })->with([
        'channel zero' => [['channel' => 0], 'channel'],
        'channel three' => [['channel' => 3], 'channel'],
        'channel text' => [['channel' => 'me'], 'channel'],
        'channel fraction' => [['channel' => 1.5], 'channel'],
        'negative start' => [['start_ms' => -1], 'start_ms'],
        'start text' => [['start_ms' => 'soon'], 'start_ms'],
        'end before start' => [['start_ms' => 2000, 'end_ms' => 1999], 'end_ms'],
        'end missing' => [['end_ms' => null], 'end_ms'],
        'text missing' => [['text' => null], 'text'],
        'text blank' => [['text' => '   '], 'text'],
        'text too long' => [['text' => str_repeat('x', 20001)], 'text'],
        'speaker too long' => [['speaker' => str_repeat('s', 41)], 'speaker'],
        'words not a list' => [['words' => 'nope'], 'words'],
    ]);

    it('accepts a segment whose end equals its start', function () {
        $this->postJson($this->url, liveTranscript([Fixtures::segment(['start_ms' => 1000, 'end_ms' => 1000])]))->assertCreated();
    });

    it('reports the position of the bad segment when only one of many is wrong', function () {
        $this->postJson($this->url, liveTranscript([
            Fixtures::segment(), Fixtures::segment(['start_ms' => 3000, 'end_ms' => 4000]), Fixtures::segment(['channel' => 9]),
        ]))->assertStatus(422)->assertJsonValidationErrors(['segments.2.channel'])->assertJsonMissingValidationErrors(['segments.0.channel', 'segments.1.channel']);
    });

    it('stores the captions of a long call in order', function () {
        $this->session->update(['duration_ms' => 3 * 3600 * 1000]);
        $segments = [];
        for ($i = 0; $i < 1500; $i++) {
            $segments[] = ['channel' => ($i % 2) + 1, 'start_ms' => $i * 7000, 'end_ms' => $i * 7000 + 6000, 'text' => "turn {$i}"];
        }
        shuffle($segments);

        $response = $this->postJson($this->url, liveTranscript($segments))->assertCreated();

        expect($response->json('segments'))->toHaveCount(1500)
            ->and($response->json('segments.0.text'))->toBe('turn 0')
            ->and($response->json('segments.1499.text'))->toBe('turn 1499');
    });
});

describe('final transcripts from the device', function () {
    it('is refused while a server-side provider transcribes the session', function () {
        $this->session->update(['final_provider' => 'assemblyai']);

        $this->postJson($this->url, liveTranscript([], ['kind' => 'final']))->assertStatus(422)->assertJsonValidationErrors(['kind']);
        expect(Transcript::query()->count())->toBe(0);
    });

    it('is accepted when the session\'s provider is on-device and completes the session', function () {
        $this->session->update(['final_provider' => 'parakeet_local']);

        $response = $this->postJson($this->url, liveTranscript([], ['kind' => 'final', 'provider' => 'parakeet_local']))->assertCreated();

        $response->assertJsonPath('kind', 'final')->assertJsonPath('status', 'completed');
        expect($this->session->refresh()->status)->toBe(SessionStatus::Completed);
        $item = collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $this->session->id);
        expect($item['transcript']['final_status'])->toBe('completed')->and($item['transcript']['final_transcript_id'])->toBe($response->json('id'));
    });

    it('takes over an awaiting_device transcript created by the final job', function () {
        $this->session->update(['final_provider' => 'parakeet_local']);
        $waiting = $this->session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'parakeet_local', 'status' => TranscriptStatus::AwaitingDevice]);

        $response = $this->postJson($this->url, liveTranscript([], ['kind' => 'final', 'provider' => 'parakeet_local']))->assertCreated();

        expect($response->json('id'))->toBe($waiting->id)->and($waiting->refresh()->status)->toBe(TranscriptStatus::Completed);
    });

    it('follows the provider pinned at session creation, not the current setting', function () {
        $this->session->update(['final_provider' => 'assemblyai']);
        app(SettingsRepository::class)->set('provider.final_transcript', 'parakeet_local');

        $this->postJson($this->url, liveTranscript([], ['kind' => 'final']))->assertStatus(422);
    });
});

describe('ownership', function () {
    it('answers 404 for a session that does not exist, belongs to someone else, or was deleted', function () {
        $body = liveTranscript();
        $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/transcripts', $body)->assertNotFound();

        $foreign = CaptureSession::factory()->create();
        $this->postJson("/api/sessions/{$foreign->id}/transcripts", $body)->assertNotFound();
        expect(Transcript::query()->where('capture_session_id', $foreign->id)->count())->toBe(0);

        $this->session->delete();
        $this->postJson($this->url, $body)->assertNotFound();
    });

    it('lets another device of the same owner add captions, since the library is shared', function () {
        [, $other] = $this->enroll($this->device->user);

        $this->asToken($other)->postJson($this->url, liveTranscript())->assertCreated();
    });
});
