<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Plan;
use App\Models\SentItem;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sharing\Teammates;
use App\Services\TranscribeLater;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
    TranscribeLater::$lockWaitSeconds = 0;
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

        // 100 less the 2 minutes of the recording still being transcribed ($this->done).
        expect($estimate->json())->toBe(['minutes' => 6, 'minutes_left' => 98,
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
            ->assertJson(['code' => 'budget_exceeded', 'minutes_needed' => 6, 'minutes_left' => 3]); // 5 less the 2 in flight

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

describe('for the web console (TranscribeLater, no device)', function () {
    it('lets a person start only their own audio-only recordings, and never a viewer', function () {
        $workspace = Workspace::query()->findOrFail($this->device->workspace_id);
        $mine = audioOnlyCall($this);
        $member = User::factory()->create();
        app(Workspaces::class)->addMember($workspace, $member);
        $theirs = CaptureSession::factory()->create(['user_id' => $member->id, 'workspace_id' => $workspace->id, 'status' => SessionStatus::AudioOnly, 'transcribe' => false]);
        $viewer = User::factory()->create();
        app(Workspaces::class)->addMember($workspace, $viewer, WorkspaceRole::Viewer);
        $later = app(TranscribeLater::class);
        app('auth')->forgetGuards();

        // A viewer starts nothing, one or many.
        expect(fn () => $later->startFor($viewer, $workspace->id, $mine))->toThrow(ApiError::class, 'viewer')
            ->and(fn () => $later->startManyFor($viewer, $workspace->id, [$mine]))->toThrow(ApiError::class, 'viewer');
        // A member starts their own only: another person's recording is not found, one or many.
        expect(fn () => $later->startFor($member, $workspace->id, $mine))->toThrow(ApiError::class, 'Session not found.');
        expect($later->startManyFor($member, $workspace->id, [$mine, $theirs->id]))
            ->toMatchArray(['started' => [$theirs->id], 'skipped' => [['id' => $mine, 'code' => 'not_found']]])
            ->and($later->estimateManyFor($member, $workspace->id, [$mine])['items'])->toBe([]);
        expect(CaptureSession::query()->withoutGlobalScopes()->findOrFail($mine)->status)->toBe(SessionStatus::AudioOnly);
        // The owner starts theirs.
        expect($later->startFor($this->owner, $workspace->id, $mine)->kind->value)->toBe('final');
    });
});

describe('spending the same minutes twice', function () {
    it('counts a transcript started and not finished against the minutes left', function () {
        [$first, $second] = [audioOnlyCall($this), audioOnlyCall($this)];
        minutesLimit($this->owner, 3);

        $this->postJson("/api/sessions/{$first}/transcribe")->assertStatus(202);
        // Nothing is in the ledger yet, but the first one's 2 minutes are spoken for.
        $this->postJson("/api/sessions/{$second}/transcribe")->assertForbidden()
            ->assertJson(['code' => 'budget_exceeded', 'minutes_needed' => 2, 'minutes_left' => 1]);
        expect(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $second)['transcribe_estimate']['minutes_left'])->toBe(1);
    });

    it('lets one start of a workspace run at a time', function () {
        $id = audioOnlyCall($this);
        $held = Cache::lock('transcribe-later:'.$this->device->workspace_id, 30);
        expect($held->get())->toBeTrue();

        $this->postJson("/api/sessions/{$id}/transcribe")->assertStatus(429)->assertJson(['code' => 'rate_limited'])->assertHeader('Retry-After', '5');
        $this->postJson('/api/sessions/transcribe', ['ids' => [$id]])->assertStatus(429);
        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly);

        $held->release();
        $this->postJson("/api/sessions/{$id}/transcribe")->assertStatus(202);
    });

    it('throttles bulk like the one-recording call', function () {
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/sessions/transcribe', ['ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']])->assertStatus(202);
        }
        $this->postJson('/api/sessions/transcribe', ['ids' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']])->assertStatus(429);
    });
});

it('shows no estimate on a recording a teammate sent: the minutes left are the sender\'s', function () {
    $mate = User::factory()->create();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
    $sent = CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id, 'status' => SessionStatus::AudioOnly, 'transcribe' => false]);
    app(Teammates::class)->send($mate, $sent, [$this->owner->email], null);
    app('auth')->forgetGuards();

    $shared = collect($this->getJson('/api/library?folder=shared')->assertOk()->json('data'))->firstWhere('id', $sent->id);

    expect($shared)->not->toBeNull()->and($shared['status'])->toBe('audio_only')->and($shared['transcribe_estimate'])->toBeNull();
});

describe('the review of 2356ca5c', function () {
    it('starts an audio-only recording through "Re-transcribe as" with the same checks', function () {
        $id = audioOnlyCall($this);
        minutesLimit($this->owner, 1);
        $this->postJson("/api/sessions/{$id}/retranscribe", ['language' => 'ur'])->assertForbidden()->assertJson(['code' => 'budget_exceeded', 'minutes_needed' => 2]);
        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly);

        minutesLimit($this->owner, null);
        $this->postJson("/api/sessions/{$id}/retranscribe", ['language' => 'ur'])->assertStatus(202);
        $session = CaptureSession::query()->findOrFail($id);
        expect($session->status)->toBe(SessionStatus::Processing)->and($session->transcribe)->toBeTrue()->and($session->language)->toBe('ur');
    });

    it('starts an audio-only recording from the console\'s "Transcribe again" with the same checks', function () {
        Filament::setCurrentPanel('app');
        $id = audioOnlyCall($this);
        $this->actingAs($this->owner, 'web');
        minutesLimit($this->owner, 1);

        Livewire::test(ViewCaptureSession::class, ['record' => $id])
            // Since v2.1 the glossary's words (docs/PLAN_V2_1_POLISH.md section 1): never "Not transcribed".
            ->callAction('retry', data: ['language' => ''])->assertNotified('Still audio only');
        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly);

        minutesLimit($this->owner, null);
        Livewire::test(ViewCaptureSession::class, ['record' => $id])
            ->callAction('retry', data: ['language' => ''])->assertNotified('Transcription queued');
        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::Processing);
    });

    it('keeps a saved copy of an audio-only recording audio only', function () {
        $mate = User::factory()->create();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
        $mateDevice = Fx::device($mate, ['workspace_id' => $this->device->workspace_id]);
        $this->asToken(Fx::token($mateDevice));
        $sent = audioOnlyCall($this);
        app(Teammates::class)->send($mate, CaptureSession::query()->withoutGlobalScopes()->findOrFail($sent), [$this->owner->email], null);
        $this->asToken($this->token);
        $item = SentItem::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->postJson("/api/shared-with-me/{$item->id}/save")->assertSuccessful();

        $copy = CaptureSession::query()->where('user_id', $this->owner->id)->where('origin', CaptureSession::ORIGIN_SAVED)->sole();
        expect($copy->status)->toBe(SessionStatus::AudioOnly)->and($copy->transcribe)->toBeFalse()
            ->and(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $copy->id)['transcribe_estimate'])->not->toBeNull();
    });

    it('puts a recording back to audio only when its transcript cannot start, with no transcript left to run', function () {
        $id = audioOnlyCall($this);
        minutesLimit($this->owner, 100);
        $left = fn (): int => $this->postJson('/api/sessions/transcribe-estimate', ['ids' => [$id]])->json('minutes_left');
        expect($left())->toBe(100);
        $real = app(Dispatcher::class);
        // The transcript row is made, then handing its job to the queue fails.
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

        $this->postJson("/api/sessions/{$id}/transcribe")->assertServerError();

        $session = CaptureSession::query()->findOrFail($id);
        expect($session->status)->toBe(SessionStatus::AudioOnly)->and($session->transcribe)->toBeFalse()
            // Nothing for stt:reconcile to requeue, and no minutes held for it.
            ->and(Transcript::query()->where('capture_session_id', $id)->count())->toBe(0)
            ->and($left())->toBe(100);

        Bus::swap($real);
        $this->postJson("/api/sessions/{$id}/transcribe")->assertStatus(202);
        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::Processing)->and($left())->toBe(98);
    });

    it('lists 6 audio-only recordings in the same queries as 1', function () {
        $count = function (): int {
            app('auth')->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/library')->assertOk();
            // Sanctum notes a token's last use now and then, whatever the page: not a query of the rows.
            $n = count(array_filter(DB::getQueryLog(), fn (array $q): bool => ! str_contains($q['query'], 'personal_access_tokens')));
            DB::disableQueryLog();

            return $n;
        };
        audioOnlyCall($this);
        $count();
        $one = $count();
        foreach (range(1, 5) as $_) {
            audioOnlyCall($this);
        }

        expect($count())->toBe($one);
    });

    it('turns audio-only recordings into completed ones on rollback, so the release before can read them', function () {
        $id = audioOnlyCall($this);
        $migration = 'database/migrations/2026_12_23_000001_add_transcribe_later.php';

        Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
        expect(DB::table('capture_sessions')->where('id', $id)->value('status'))->toBe('completed')
            ->and(Schema::hasColumn('capture_sessions', 'transcribe'))->toBeFalse();

        Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
        expect(Schema::hasColumn('clients', 'transcribe_calls'))->toBeTrue();
    });

    it('starts none of many on Free without a key', function () {
        $ids = [audioOnlyCall($this), audioOnlyCall($this)];
        Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => Plan::free()->id]);
        $this->owner->forceFill(['entitlement_overrides' => ['limits' => ['trial_minutes' => 0]]])->save();

        $this->postJson('/api/sessions/transcribe', ['ids' => $ids])->assertForbidden()->assertJson(['code' => 'needs_transcription_key']);
        expect(CaptureSession::query()->whereKey($ids)->pluck('status')->unique()->all())->toBe([SessionStatus::AudioOnly]);
    });

    it('lets a finish say Transcribe after the open create said Save audio', function () {
        $open = $this->openSession(['transcribe' => false]);
        $finish = $this->postJson("/api/sessions/{$open}/finish", Fixtures::finishBody($bytes = Fixtures::audio(1500), ['transcribe' => true]))->assertOk();
        $this->uploadAll((string) $finish->json('recordings.0.id'), $bytes, (int) $finish->json('recordings.0.upload.chunk_size_bytes'));
        $this->postJson('/api/recordings/'.$finish->json('recordings.0.id').'/complete')->assertSuccessful();

        $session = CaptureSession::query()->findOrFail($open);
        expect($session->transcribe)->toBeTrue()->and($session->status)->toBe(SessionStatus::Processing);
    });

    it('counts no more than the trial has left on a plan without transcription on EchoLedger', function () {
        $ids = [audioOnlyCall($this), audioOnlyCall($this), audioOnlyCall($this)];
        $free = Plan::free();
        $free->forceFill(['trial_minutes' => 5, 'monthly_minutes' => null])->save();
        Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => $free->id]);

        expect(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $ids[0])['transcribe_estimate'])->toBe(['minutes' => 2, 'minutes_left' => 5]);
        $this->postJson('/api/sessions/transcribe', ['ids' => $ids])->assertForbidden()->assertJson(['code' => 'budget_exceeded', 'minutes_needed' => 6, 'minutes_left' => 5]);
    });

    it('shows no estimate on a sent recording opened on its own either', function () {
        $mate = User::factory()->create();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
        $sent = CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id, 'status' => SessionStatus::AudioOnly, 'transcribe' => false]);
        app(Teammates::class)->send($mate, $sent, [$this->owner->email], null);
        app('auth')->forgetGuards();

        expect($this->getJson("/api/sessions/{$sent->id}")->assertOk()->json('transcribe_estimate'))->toBeNull();
    });
});

describe('the review of 5f74e162', function () {
    it('fails a final transcript that was there before the start, rather than leaving it to run', function () {
        $id = audioOnlyCall($this);
        $before = Transcript::query()->create(['capture_session_id' => $id, 'kind' => 'final', 'provider' => 'assemblyai', 'status' => 'failed', 'language' => 'en']);
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

        $this->postJson("/api/sessions/{$id}/transcribe")->assertServerError();

        expect($before->fresh()->status->value)->toBe('failed')->and(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly);
    });

    it('starts the others of many when one cannot start, listing it as skipped', function () {
        [$first, $second] = [audioOnlyCall($this), audioOnlyCall($this)];
        $calls = 0;
        Bus::shouldReceive('dispatch')->andReturnUsing(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new RuntimeException('queue down');
            }

            return null;
        });

        $this->postJson('/api/sessions/transcribe', ['ids' => [$first, $second]])->assertStatus(202)
            ->assertJson(['started' => [$second], 'skipped' => [['id' => $first, 'code' => 'not_started']], 'minutes' => 2]);
        expect(CaptureSession::query()->findOrFail($first)->status)->toBe(SessionStatus::AudioOnly)
            ->and(CaptureSession::query()->findOrFail($second)->status)->toBe(SessionStatus::Processing);
    });

    it('estimates nothing for a viewer from the console', function () {
        $workspace = Workspace::query()->findOrFail($this->device->workspace_id);
        $viewer = User::factory()->create();
        app(Workspaces::class)->addMember($workspace, $viewer, WorkspaceRole::Viewer);
        app('auth')->forgetGuards();

        expect(fn () => app(TranscribeLater::class)->estimateManyFor($viewer, $workspace->id, [audioOnlyCall($this)]))->toThrow(ApiError::class, 'viewer');
    });

    it('refuses to start outside tests when the cache keeps no locks between requests', function () {
        $id = audioOnlyCall($this);
        config(['cache.default' => 'array']);
        $env = app()['env'];
        app()['env'] = 'production';
        try {
            expect(fn () => app(TranscribeLater::class)->startFor($this->owner, (int) $this->device->workspace_id, $id))
                ->toThrow(fn (ApiError $e) => expect([$e->getStatusCode(), $e->apiCode()])->toBe([503, 'server_busy']));
        } finally {
            app()['env'] = $env;
        }
        expect(CaptureSession::query()->findOrFail($id)->status)->toBe(SessionStatus::AudioOnly);
    });

    it('keeps the language of an audio-only recording when "Re-transcribe as" cannot start it', function () {
        $id = audioOnlyCall($this, ['language' => 'en']);
        minutesLimit($this->owner, 1);

        $this->postJson("/api/sessions/{$id}/retranscribe", ['language' => 'ur'])->assertForbidden();

        expect(CaptureSession::query()->findOrFail($id)->language)->toBe('en');
    });

    it('throttles "Re-transcribe as" like Transcribe', function () {
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/retranscribe', ['language' => 'ur'])->assertNotFound();
        }
        $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/retranscribe', ['language' => 'ur'])->assertStatus(429);
    });

    it('starts an audio-only recording from the console\'s "Re-transcribe as" with the person\'s checks', function () {
        Filament::setCurrentPanel('app');
        $id = audioOnlyCall($this, ['language' => 'en']);
        $this->actingAs($this->owner, 'web');
        minutesLimit($this->owner, 1);

        Livewire::test(ViewCaptureSession::class, ['record' => $id])->callAction('retranscribe_as', data: ['language' => 'ur'])->assertNotified();
        $session = CaptureSession::query()->findOrFail($id);
        expect($session->status)->toBe(SessionStatus::AudioOnly)->and($session->language)->toBe('en');

        minutesLimit($this->owner, null);
        Livewire::test(ViewCaptureSession::class, ['record' => $id])->callAction('retranscribe_as', data: ['language' => 'ur'])->assertNotified();
        $session = CaptureSession::query()->findOrFail($id);
        expect($session->status)->toBe(SessionStatus::Processing)->and($session->language)->toBe('ur');
    });
});

describe('bulk Transcribe on the queue (EL-UX\'s finding)', function () {
    beforeEach(function () {
        minutesLimit($this->owner, null);
        // 50 short recordings (30 s each): each one alone would take the fast path.
        $this->short = CaptureSession::factory()->count(50)->create(['user_id' => $this->owner->id, 'workspace_id' => $this->device->workspace_id,
            'status' => SessionStatus::AudioOnly, 'transcribe' => false, 'duration_ms' => 30_000])->pluck('id')->all();
    });

    it('queues every start of the apps\' bulk Transcribe and runs none inside the request', function () {
        Queue::fake();

        $answer = $this->postJson('/api/sessions/transcribe', ['ids' => $this->short])->assertStatus(202);

        expect($answer->json('started'))->toHaveCount(50);
        Queue::assertPushed(TranscribeFinalJob::class, 50);
        // Nothing was transcribed in the request: every final transcript is still waiting for its job.
        expect(Transcript::query()->whereIn('capture_session_id', $this->short)->where('kind', 'final')->pluck('status')->unique()->map(fn ($s) => $s->value)->all())
            ->toBe(['pending']);
    });

    it('never takes the fast path for many, as a server would run it, while one start still does', function () {
        Bus::fake([TranscribeFinalJob::class]);
        $later = app(TranscribeLater::class);
        $workspace = (int) $this->device->workspace_id;
        $env = app()['env'];
        // As on a server: the fast path runs a short recording's job right away (after the response, or in the
        // console now), never through the queue; and a cache that keeps locks between requests, as a server needs.
        config(['cache.default' => 'file']);
        app()['env'] = 'production';
        try {
            $many = $later->startManyFor($this->owner, $workspace, array_slice($this->short, 0, 49));
            Bus::assertNotDispatchedSync(TranscribeFinalJob::class);
            Bus::assertNotDispatchedAfterResponse(TranscribeFinalJob::class);
            Bus::assertDispatched(TranscribeFinalJob::class, 49);

            // One start of a short recording keeps the fast path.
            $later->startFor($this->owner, $workspace, $this->short[49]);
            Bus::assertDispatchedSync(TranscribeFinalJob::class, 1);
        } finally {
            app()['env'] = $env;
        }
        expect($many['started'])->toHaveCount(49);
    });

    it('keeps the claim, the budget and the key as they were for a bulk start', function () {
        Queue::fake();
        minutesLimit($this->owner, 10);

        $this->postJson('/api/sessions/transcribe', ['ids' => $this->short])->assertForbidden()->assertJson(['code' => 'budget_exceeded']);

        Queue::assertNothingPushed();
        expect(CaptureSession::query()->whereIn('id', $this->short)->pluck('status')->unique()->all())->toBe([SessionStatus::AudioOnly]);
    });
});
