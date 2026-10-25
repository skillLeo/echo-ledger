<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\UploadStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use App\Services\SessionLifecycle;
use App\Services\SettingsRepository;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Api\Support\Shape;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Session lifecycle" (since v1.1): open session (POST /api/sessions without artifacts), finish
 * (POST /api/sessions/{id}/finish), the one-shot create that finishes a still-open session, and the stale sweep.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->bytes = Fixtures::audio(3000);
});

/** @return array<string, mixed> the answer shape shared by create, open and finish */
function lifecycleAnswerShape(): array
{
    return Shape::object([
        'session' => Shape::object(['id' => 'string', 'client_ref' => 'string', 'status' => 'string', 'deleted' => 'bool']),
        'recordings' => Shape::list(Shape::object([
            'id' => 'string', 'role' => 'string',
            'upload' => Shape::object(['chunk_size_bytes' => 'int', 'total_chunks' => 'int', 'received_chunks' => Shape::list('int'), 'status' => 'string', 'error' => 'string?']),
        ])),
        'required_artifacts' => Shape::list('string'),
    ]);
}

describe('opening a session', function () {
    it('answers 201 with status recording and no upload slots', function () {
        $response = $this->postJson('/api/sessions', Fixtures::openSessionBody(['client_ref' => 'open-1', 'source_app' => 'microsoft_teams']))->assertCreated();

        expect(Shape::violations($response->json(), lifecycleAnswerShape()))->toBe([])
            ->and($response->json('session.status'))->toBe('recording')
            ->and($response->json('session.source_app'))->toBe('microsoft_teams')
            ->and($response->json('session.source_app_label'))->toBe('Microsoft Teams')
            ->and($response->json('session.title'))->toBe('Microsoft Teams call')
            ->and($response->json('session.ended_at'))->toBeNull()
            ->and($response->json('recordings'))->toBe([])
            ->and($response->json('required_artifacts'))->toBe(['primary']);
        $session = CaptureSession::query()->sole();
        expect($session->status)->toBe(SessionStatus::Recording)
            ->and($session->device_id)->toBe($this->device->id)
            ->and($session->final_provider)->toBe('assemblyai');
    });

    it('fixes the final provider when the session is opened', function () {
        app(SettingsRepository::class)->set('provider.final_transcript', 'elevenlabs');
        $id = $this->openSession();
        app(SettingsRepository::class)->set('provider.final_transcript', 'assemblyai');

        expect(CaptureSession::query()->find($id)->final_provider)->toBe('elevenlabs');
    });

    it('stores the start in UTC whatever offset the device sends', function () {
        $id = $this->openSession(['started_at' => '2026-10-24T14:00:00+05:00']);

        expect(CaptureSession::query()->find($id)->started_at->toIso8601String())->toBe('2026-10-24T09:00:00+00:00');
    });

    it('answers 200 with the same session when the open request is repeated', function () {
        $first = $this->postJson('/api/sessions', Fixtures::openSessionBody(['client_ref' => 'same-ref']))->assertCreated();

        $again = $this->postJson('/api/sessions', Fixtures::openSessionBody(['client_ref' => 'same-ref', 'title' => 'changed']))->assertOk();

        expect($again->json('session.id'))->toBe($first->json('session.id'))
            ->and($again->json('session.status'))->toBe('recording')
            ->and(CaptureSession::query()->count())->toBe(1)
            ->and(CaptureSession::query()->sole()->title)->toBeNull();
    });

    it('refuses an eleventh open session of the same device with 409 too_many_open_sessions', function () {
        foreach (range(1, SessionLifecycle::MAX_OPEN_SESSIONS) as $i) {
            $this->openSession(['client_ref' => "open-{$i}"]);
        }

        $this->postJson('/api/sessions', Fixtures::openSessionBody(['client_ref' => 'one-too-many']))
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Too many recordings are still open. Finish or discard some first.', 'code' => 'too_many_open_sessions']);
        expect(CaptureSession::query()->count())->toBe(SessionLifecycle::MAX_OPEN_SESSIONS);
    });

    it('counts only this device\'s sessions that are still recording towards the limit', function () {
        [$other] = $this->enroll($this->device->user);
        CaptureSession::factory()->open()->count(5)->create(['user_id' => $this->device->user_id, 'device_id' => $other->id]);
        CaptureSession::factory()->count(5)->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Uploading]);
        foreach (range(1, SessionLifecycle::MAX_OPEN_SESSIONS - 1) as $i) {
            $this->openSession(['client_ref' => "mine-{$i}"]);
        }

        $this->postJson('/api/sessions', Fixtures::openSessionBody(['client_ref' => 'last-allowed']))->assertCreated();
    });

    it('still allows a one-shot create while ten sessions are open', function () {
        foreach (range(1, SessionLifecycle::MAX_OPEN_SESSIONS) as $i) {
            $this->openSession(['client_ref' => "open-{$i}"]);
        }

        $this->createSession($this->bytes)->assertCreated()->assertJsonPath('session.status', 'uploading');
    });

    it('answers 404 client_not_found for a client of another owner', function () {
        $foreign = Client::factory()->create();

        $this->postJson('/api/sessions', Fixtures::openSessionBody(['client_id' => $foreign->id]))
            ->assertNotFound()->assertJsonPath('code', 'client_not_found');
        expect(CaptureSession::query()->count())->toBe(0);
    });

    it('rejects an empty artifacts list instead of opening a session', function () {
        $this->postJson('/api/sessions', Fixtures::openSessionBody(['artifacts' => []]))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['artifacts']);
        expect(CaptureSession::query()->count())->toBe(0);
    });

    it('does not list an open session in the library unless asked for', function () {
        $open = $this->openSession();
        $done = $this->createSession($this->bytes)->json('session.id');

        $ids = array_column($this->getJson('/api/library')->assertOk()->json('data'), 'id');
        $sinceIds = array_column($this->getJson('/api/library?since='.urlencode(now()->subDay()->toAtomString()))->json('data'), 'id');
        $asked = array_column($this->getJson('/api/library?status=recording')->json('data'), 'id');

        expect($ids)->toBe([$done])->and($sinceIds)->toBe([$done])->and($asked)->toBe([$open]);
    });

    it('syncs the tombstone of a discarded open session', function () {
        $open = $this->openSession();
        $this->deleteJson("/api/sessions/{$open}")->assertNoContent();

        $items = $this->getJson('/api/library?since='.urlencode(now()->subDay()->toAtomString()))->json('data');

        expect($items)->toHaveCount(1)->and($items[0])->toMatchArray(['id' => $open, 'deleted' => true]);
    });
});

describe('finishing a session', function () {
    it('answers 200 with upload slots in the same shape as the create answer', function () {
        $id = $this->openSession(['title' => 'Draft title']);

        $response = $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, ['title' => 'Budget review']))->assertOk();

        expect(Shape::violations($response->json(), lifecycleAnswerShape()))->toBe([])
            ->and($response->json('session.id'))->toBe($id)
            ->and($response->json('session.status'))->toBe('uploading')
            ->and($response->json('session.title'))->toBe('Budget review')
            ->and($response->json('session.ended_at'))->toBe('2026-10-24T09:42:10+00:00')
            ->and($response->json('session.duration_ms'))->toBe(2530000)
            ->and($response->json('recordings'))->toHaveCount(1)
            ->and($response->json('recordings.0.role'))->toBe('primary')
            ->and($response->json('recordings.0.upload'))->toMatchArray(['received_chunks' => [], 'status' => 'pending', 'error' => null]);
    });

    it('stores the end in UTC whatever offset the device sends', function () {
        $id = $this->openSession();

        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, ['ended_at' => '2026-10-24T14:42:10.500+05:00']))->assertOk();

        expect(CaptureSession::query()->find($id)->ended_at->toIso8601String())->toBe('2026-10-24T09:42:10+00:00');
    });

    it('lets the upload run to the end exactly like a one-shot create', function () {
        $id = $this->openSession();
        $finished = $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes))->assertOk();
        $recording = (string) $finished->json('recordings.0.id');

        $this->uploadAll($recording, $this->bytes, (int) $finished->json('recordings.0.upload.chunk_size_bytes'));
        $this->postJson("/api/recordings/{$recording}/complete")->assertStatus(202);

        expect(Recording::query()->find($recording)->upload_status)->toBe(UploadStatus::Ready);
    });

    it('is idempotent: a repeated finish changes nothing and returns the same slots', function () {
        $id = $this->openSession();
        $first = $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, ['title' => 'First']))->assertOk();

        $again = $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody(Fixtures::audio(5000), ['title' => 'Second', 'duration_ms' => 1]))->assertOk();

        expect($again->json('recordings'))->toBe($first->json('recordings'))
            ->and($again->json('session.title'))->toBe('First')
            ->and($again->json('session.duration_ms'))->toBe(2530000)
            ->and(Recording::query()->count())->toBe(1);
    });

    it('answers 404 session_not_found for an unknown id', function () {
        $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/finish', Fixtures::finishBody($this->bytes))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Session not found.', 'code' => 'session_not_found']);
    });

    it('answers 404 session_not_found to another device of the same owner', function () {
        $id = $this->openSession();
        [, $phone] = $this->enroll($this->device->user);

        $this->asToken($phone)->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes))
            ->assertNotFound()->assertJsonPath('code', 'session_not_found');
        expect(CaptureSession::query()->find($id)->status)->toBe(SessionStatus::Recording);
    });

    it('answers 404 session_not_found for a discarded or swept session', function () {
        $id = $this->openSession();
        $this->deleteJson("/api/sessions/{$id}")->assertNoContent();

        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes))
            ->assertNotFound()->assertJsonPath('code', 'session_not_found');
        expect(Recording::query()->count())->toBe(0);
    });

    it('replaces the client when the key is present, clears it for null and keeps it when absent', function () {
        $acme = Client::factory()->create(['user_id' => $this->device->user_id]);
        $nova = Client::factory()->create(['user_id' => $this->device->user_id]);
        $replace = $this->openSession(['client_id' => $acme->id]);
        $clear = $this->openSession(['client_id' => $acme->id]);
        $keep = $this->openSession(['client_id' => $acme->id]);

        $this->postJson("/api/sessions/{$replace}/finish", Fixtures::finishBody($this->bytes, ['client_id' => $nova->id]))->assertOk()->assertJsonPath('session.client.id', $nova->id);
        $this->postJson("/api/sessions/{$clear}/finish", Fixtures::finishBody($this->bytes, ['client_id' => null]))->assertOk()->assertJsonPath('session.client', null);
        $this->postJson("/api/sessions/{$keep}/finish", Fixtures::finishBody($this->bytes))->assertOk()->assertJsonPath('session.client.id', $acme->id);
    });

    it('replaces the title when the key is present, even with null, and keeps it when absent', function () {
        $cleared = $this->openSession(['title' => 'Typed during the call']);
        $kept = $this->openSession(['title' => 'Typed during the call']);

        $this->postJson("/api/sessions/{$cleared}/finish", Fixtures::finishBody($this->bytes, ['title' => null]))->assertOk();
        $this->postJson("/api/sessions/{$kept}/finish", Fixtures::finishBody($this->bytes))->assertOk();

        expect(CaptureSession::query()->find($cleared)->title)->toBeNull()
            ->and(CaptureSession::query()->find($kept)->title)->toBe('Typed during the call');
    });

    it('answers 404 client_not_found for a foreign client and leaves the session open', function () {
        $id = $this->openSession();
        $foreign = Client::factory()->create();

        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, ['client_id' => $foreign->id]))
            ->assertNotFound()->assertJsonPath('code', 'client_not_found');
        expect(CaptureSession::query()->find($id)->status)->toBe(SessionStatus::Recording)
            ->and(Recording::query()->count())->toBe(0);
    });

    it('validates the body', function (array $override, string $field) {
        $id = $this->openSession();
        $body = Fixtures::finishBody($this->bytes, $override);
        $body = array_filter($body, fn ($value) => $value !== '__absent__');

        $this->postJson("/api/sessions/{$id}/finish", $body)
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors([$field]);
        expect(CaptureSession::query()->find($id)->status)->toBe(SessionStatus::Recording);
    })->with([
        'ended_at missing' => [['ended_at' => '__absent__'], 'ended_at'],
        'ended_at before the start' => [['ended_at' => '2026-10-24T08:59:59Z'], 'ended_at'],
        'ended_at as a number' => [['ended_at' => 1729760530], 'ended_at'],
        'duration missing' => [['duration_ms' => '__absent__'], 'duration_ms'],
        'duration negative' => [['duration_ms' => -1], 'duration_ms'],
        'duration over a day' => [['duration_ms' => 86400001], 'duration_ms'],
        'title too long' => [['title' => str_repeat('t', 201)], 'title'],
        'client id malformed' => [['client_id' => 'abc'], 'client_id'],
        'artifacts missing' => [['artifacts' => '__absent__'], 'artifacts'],
        'artifacts empty' => [['artifacts' => []], 'artifacts'],
        'four artifacts' => [['artifacts' => array_fill(0, 4, ['role' => 'primary'])], 'artifacts'],
    ]);

    it('requires exactly one primary artifact with unique roles', function () {
        $id = $this->openSession();

        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, [], ['role' => 'channel_1']))
            ->assertStatus(422)->assertJsonValidationErrors(['artifacts']);
        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, ['artifacts' => [Fixtures::artifact($this->bytes), Fixtures::artifact($this->bytes)]]))
            ->assertStatus(422)->assertJsonValidationErrors(['artifacts']);
    });

    it('accepts an end equal to the start', function () {
        $id = $this->openSession(['started_at' => '2026-10-24T09:00:00Z']);

        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, ['ended_at' => '2026-10-24T14:00:00+05:00', 'duration_ms' => 0]))->assertOk();
    });

    it('needs the sessions:write ability', function () {
        $id = $this->openSession();
        [, $readOnly] = $this->enroll($this->device->user, ['library:read']);

        $this->asToken($readOnly)->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes))
            ->assertForbidden()->assertJsonPath('code', 'missing_ability');
    });
});

describe('a one-shot create with the client_ref of an open session', function () {
    it('finishes the open session and answers 200 (crash safety)', function () {
        $id = $this->openSession(['client_ref' => 'crashed-ref', 'started_at' => '2026-10-24T09:00:00Z']);

        $response = $this->createSession($this->bytes, ['client_ref' => 'crashed-ref', 'title' => 'Recovered'])->assertOk();

        expect($response->json('session.id'))->toBe($id)
            ->and($response->json('session.status'))->toBe('uploading')
            ->and($response->json('session.title'))->toBe('Recovered')
            ->and($response->json('session.ended_at'))->toBe('2026-10-24T09:01:00+00:00')
            ->and($response->json('recordings'))->toHaveCount(1)
            ->and(CaptureSession::query()->count())->toBe(1);
    });

    it('returns a finished session unchanged on a later replay', function () {
        $id = $this->openSession(['client_ref' => 'ref-2']);
        $first = $this->createSession($this->bytes, ['client_ref' => 'ref-2'])->assertOk();

        $again = $this->createSession(Fixtures::audio(4000), ['client_ref' => 'ref-2', 'title' => 'other'])->assertOk();

        expect($again->json('recordings'))->toBe($first->json('recordings'))->and(Recording::query()->count())->toBe(1)
            ->and($again->json('session.id'))->toBe($id);
    });

    it('returns a tombstone unchanged and creates no upload slot for it', function () {
        $id = $this->openSession(['client_ref' => 'discarded-ref']);
        $this->deleteJson("/api/sessions/{$id}")->assertNoContent();

        $response = $this->createSession($this->bytes, ['client_ref' => 'discarded-ref'])->assertOk();

        expect($response->json('session'))->toMatchArray(['id' => $id, 'deleted' => true])
            ->and($response->json('recordings'))->toBe([])
            ->and(Recording::query()->count())->toBe(0);
    });

    it('does not finish an open session of another device that used the same client_ref', function () {
        [, $phone] = $this->enroll($this->device->user);
        $theirs = $this->asToken($phone)->openSession(['client_ref' => 'shared-ref']);

        $mine = $this->asToken($this->token)->createSession($this->bytes, ['client_ref' => 'shared-ref'])->assertCreated();

        expect($mine->json('session.id'))->not->toBe($theirs)
            ->and(CaptureSession::query()->find($theirs)->status)->toBe(SessionStatus::Recording);
    });
});

describe('the stale open-session sweep', function () {
    it('tombstones open sessions six hours old or older that have no recording', function () {
        $this->freezeTime();
        $stale = CaptureSession::factory()->open()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'created_at' => now()->subHours(6)]);
        $young = CaptureSession::factory()->open()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'created_at' => now()->subHours(6)->addMinute()]);
        $withRecording = CaptureSession::factory()->open()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'created_at' => now()->subHours(7)]);
        $this->makeReadyRecording($withRecording, $this->bytes);
        $finished = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'created_at' => now()->subDays(2)]);

        $swept = app(SessionLifecycle::class)->sweepStale();

        expect($swept)->toBe(1)
            ->and(CaptureSession::withTrashed()->find($stale->id)->trashed())->toBeTrue()
            ->and(CaptureSession::query()->find($young->id))->not->toBeNull()
            ->and(CaptureSession::query()->find($withRecording->id))->not->toBeNull()
            ->and(CaptureSession::query()->find($finished->id))->not->toBeNull();
    });
});
