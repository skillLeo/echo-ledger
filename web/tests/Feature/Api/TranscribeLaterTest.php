<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Audio only and Transcribe later" (1.8.0-e, served since 2.0.0-j): Save audio keeps a recording as audio
 * (`audio_only`, no transcript, no minutes), its Library item says what a transcript would use, and the person starts
 * it later, one recording or many, within their month's minutes. Only their own recordings.
 */

beforeEach(function () {
    Storage::fake('recordings');
    $this->configureAssemblyAi();
    $this->fakeAssemblyAi();
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
    $this->owner = User::query()->findOrFail($this->device->user_id);
});

/** Uploads a one-minute stereo call kept as audio only; returns its id. */
function audioOnlyCall(object $test, array $overrides = []): string
{
    $id = $test->uploadRecording(Fixtures::audio(1500), ['transcribe' => false, ...$overrides])['session'];
    expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly);

    return $id;
}

function minutesLimit(User $user, ?int $minutes): void
{
    $user->forceFill(['entitlement_overrides' => ['limits' => ['monthly_minutes' => $minutes]]])->save();
}

describe('Save audio', function () {
    it('keeps the recording as audio only: no transcript, no minutes, an estimate on its Library item', function () {
        $id = audioOnlyCall($this);

        expect(Transcript::query()->where('capture_session_id', $id)->count())->toBe(0)
            ->and(UsageLedgerEntry::query()->count())->toBe(0);
        $item = collect($this->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $id);
        // One minute, both channels billed by AssemblyAI: 2 minutes; no limit on this plan.
        expect($item['status'])->toBe('audio_only')
            ->and($item['transcribe_estimate'])->toBe(['minutes' => 2, 'minutes_left' => null]);
        expect(collect($this->getJson('/api/library?status=audio_only')->json('data'))->pluck('id')->all())->toBe([$id]);
    });

    it('transcribes as before when the app sends nothing, and an item that is not audio only has no estimate', function () {
        $id = $this->uploadRecording(Fixtures::audio(1500))['session'];

        $session = CaptureSession::query()->findOrFail($id);
        expect($session->status)->toBe(SessionStatus::Processing)->and($session->transcribe)->toBeTrue()
            ->and(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $id)['transcribe_estimate'])->toBeNull();
    });

    it('takes the choice at the open create and at finish, and a repeated finish never changes it', function () {
        $open = $this->openSession(['transcribe' => false]);
        $finish = $this->postJson("/api/sessions/{$open}/finish", Fixtures::finishBody($bytes = Fixtures::audio(1500)))->assertOk();
        $this->uploadAll((string) $finish->json('recordings.0.id'), $bytes, (int) $finish->json('recordings.0.upload.chunk_size_bytes'));
        $this->postJson('/api/recordings/'.$finish->json('recordings.0.id').'/complete')->assertSuccessful();
        expect(CaptureSession::query()->findOrFail($open)->status)->toBe(SessionStatus::AudioOnly);

        $other = $this->openSession();
        $this->postJson("/api/sessions/{$other}/finish", Fixtures::finishBody(Fixtures::audio(1500), ['transcribe' => false]))->assertOk();
        $this->postJson("/api/sessions/{$other}/finish", Fixtures::finishBody(Fixtures::audio(1500), ['transcribe' => true]))->assertOk();
        expect(CaptureSession::query()->findOrFail($other)->transcribe)->toBeFalse();

        $this->postJson('/api/sessions', Fixtures::sessionBody(Fixtures::audio(200), ['transcribe' => 'later']))->assertStatus(422);
    });
});

describe('POST /api/sessions/{id}/transcribe', function () {
    it('starts the transcript of an audio-only recording, once', function () {
        $id = audioOnlyCall($this);

        $answer = $this->postJson("/api/sessions/{$id}/transcribe")->assertStatus(202);

        expect($answer->json('session.id'))->toBe($id)->and($answer->json('session.status'))->toBe('processing')
            ->and($answer->json('session.transcribe_estimate'))->toBeNull()
            ->and($answer->json('transcript'))->toHaveKeys(['id', 'kind', 'provider', 'status', 'language'])
            ->and($answer->json('transcript.kind'))->toBe('final')->and($answer->json('transcript.provider'))->toBe('assemblyai')
            ->and(CaptureSession::query()->findOrFail($id)->transcribe)->toBeTrue();
        $this->postJson("/api/sessions/{$id}/transcribe")->assertStatus(409)->assertJson(['code' => 'not_audio_only']);
        expect(Transcript::query()->where('capture_session_id', $id)->count())->toBe(1);
    });

    it('refuses a recording that is not audio only', function () {
        $id = $this->uploadRecording(Fixtures::audio(1500))['session'];

        $this->postJson("/api/sessions/{$id}/transcribe")->assertStatus(409)->assertJson(['code' => 'not_audio_only']);
    });

    it('refuses when the month cannot cover it, saying how many minutes it needs and has, and starts nothing', function () {
        $id = audioOnlyCall($this);
        minutesLimit($this->owner, 1);

        $this->postJson("/api/sessions/{$id}/transcribe")->assertForbidden()
            ->assertJson(['code' => 'budget_exceeded', 'minutes_needed' => 2, 'minutes_left' => 1]);

        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly)
            ->and(Transcript::query()->where('capture_session_id', $id)->count())->toBe(0);
        expect(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $id)['transcribe_estimate'])->toBe(['minutes' => 2, 'minutes_left' => 1]);
    });

    it('asks for the person\'s own key on Free, and starts nothing', function () {
        $id = audioOnlyCall($this);
        Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => Plan::free()->id]);
        $this->owner->forceFill(['entitlement_overrides' => ['limits' => ['trial_minutes' => 0]]])->save();

        $this->postJson("/api/sessions/{$id}/transcribe")->assertForbidden()->assertJson(['code' => 'needs_transcription_key']);
        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly);
        // On the person's own key it would count for no plan minutes.
        expect(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $id)['transcribe_estimate']['minutes'])->toBe(0);
    });

    it('is 30 a minute per device', function () {
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/transcribe')->assertNotFound();
        }
        $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/transcribe')->assertStatus(429);
    });
});

describe('many at once, and the estimate', function () {
    beforeEach(function () {
        $this->mine = [audioOnlyCall($this), audioOnlyCall($this), audioOnlyCall($this)];
        $this->done = $this->uploadRecording(Fixtures::audio(1500))['session'];
        $this->foreign = CaptureSession::factory()->create(['status' => SessionStatus::AudioOnly, 'transcribe' => false])->id;
        $mate = User::factory()->create();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
        $this->teammate = CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id,
            'status' => SessionStatus::AudioOnly, 'transcribe' => false])->id;
        $this->ids = [...$this->mine, $this->done, $this->foreign, $this->teammate, $this->mine[0]];
    });

    it('estimates the person\'s audio-only recordings among the ids, changing nothing', function () {
        minutesLimit($this->owner, 100);

        $estimate = $this->postJson('/api/sessions/transcribe-estimate', ['ids' => $this->ids])->assertOk();

        expect($estimate->json())->toBe(['minutes' => 6, 'minutes_left' => 100,
            'items' => array_map(fn (string $id): array => ['id' => $id, 'minutes' => 2], $this->mine)]);
        expect(CaptureSession::query()->whereIn('id', $this->mine)->pluck('status')->unique()->all())->toBe([SessionStatus::AudioOnly]);
    });

    it('starts all of the person\'s audio-only ones and says why each other id was skipped', function () {
        $answer = $this->postJson('/api/sessions/transcribe', ['ids' => $this->ids])->assertStatus(202);

        expect($answer->json('started'))->toBe($this->mine)
            ->and($answer->json('skipped'))->toBe([
                ['id' => $this->done, 'code' => 'not_audio_only'],
                ['id' => $this->foreign, 'code' => 'not_found'],
                ['id' => $this->teammate, 'code' => 'not_found'],
            ])
            ->and($answer->json('minutes'))->toBe(6);
        expect(CaptureSession::query()->whereIn('id', $this->mine)->pluck('status')->unique()->all())->toBe([SessionStatus::Processing])
            ->and(CaptureSession::query()->withoutGlobalScopes()->whereKey([$this->foreign, $this->teammate])->pluck('status')->unique()->all())->toBe([SessionStatus::AudioOnly]);
        $this->postJson('/api/sessions/transcribe', ['ids' => $this->mine])->assertStatus(202)->assertJson(['started' => [], 'minutes' => 0]);
    });

    it('starts none when the month cannot cover all of them', function () {
        minutesLimit($this->owner, 5);

        $this->postJson('/api/sessions/transcribe', ['ids' => $this->ids])->assertForbidden()
            ->assertJson(['code' => 'budget_exceeded', 'minutes_needed' => 6, 'minutes_left' => 5]);

        expect(CaptureSession::query()->whereIn('id', $this->mine)->pluck('status')->unique()->all())->toBe([SessionStatus::AudioOnly]);
    });

    it('takes 1 to 50 ids', function () {
        foreach (['/api/sessions/transcribe', '/api/sessions/transcribe-estimate'] as $uri) {
            $this->postJson($uri, ['ids' => []])->assertStatus(422);
            $this->postJson($uri, ['ids' => array_fill(0, 51, $this->mine[0])])->assertStatus(422);
            $this->postJson($uri, ['ids' => ['short']])->assertStatus(422);
        }
    });
});

describe('who may', function () {
    it('never starts or estimates another workspace\'s or a teammate\'s recording', function () {
        $foreign = CaptureSession::factory()->create(['status' => SessionStatus::AudioOnly, 'transcribe' => false]);
        $mate = User::factory()->create();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
        $theirs = CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id, 'status' => SessionStatus::AudioOnly, 'transcribe' => false]);

        foreach ([$foreign, $theirs] as $session) {
            $this->postJson("/api/sessions/{$session->id}/transcribe")->assertNotFound()->assertJson(['code' => 'session_not_found']);
        }
        expect($this->postJson('/api/sessions/transcribe-estimate', ['ids' => [$foreign->id, $theirs->id]])->json('items'))->toBe([]);
        expect(Transcript::query()->whereIn('capture_session_id', [$foreign->id, $theirs->id])->count())->toBe(0);
    });

    it('refuses a viewer\'s device', function () {
        $owner = Fx::user();
        $viewer = Fx::user();
        $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
        app(Workspaces::class)->addMember($workspace, $viewer, WorkspaceRole::Viewer);
        $viewer->forceFill(['current_workspace_id' => $workspace->id])->save();
        $token = Fx::token(Fx::device($viewer, ['workspace_id' => $workspace->id]));
        app('auth')->forgetGuards();

        $this->withToken($token)->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/transcribe')->assertForbidden()->assertJson(['code' => 'not_allowed']);
        $this->withToken($token)->postJson('/api/sessions/transcribe', ['ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']])->assertForbidden();
    });
});

it('keeps "Always transcribe calls in this project" on the project', function () {
    $client = Client::factory()->create(['user_id' => $this->owner->id]);
    expect(collect($this->getJson('/api/clients')->assertOk()->json('data'))->firstWhere('id', $client->id)['transcribe_calls'])->toBe('ask');

    $this->patchJson("/api/clients/{$client->id}", ['transcribe_calls' => 'always'])->assertOk()->assertJsonPath('data.transcribe_calls', 'always');
    $this->patchJson("/api/clients/{$client->id}", ['transcribe_calls' => 'sometimes'])->assertStatus(422);
    expect($client->fresh()->transcribe_calls)->toBe('always');
});
