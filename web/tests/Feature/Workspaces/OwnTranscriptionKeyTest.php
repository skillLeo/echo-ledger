<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Auth\EditProfile;
use App\Jobs\TranscribeFinalJob;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\SignUp;
use App\Services\CredentialVault;
use App\Services\FinalTranscriptCoordinator;
use App\Services\SettingsRepository;
use App\Stt\Audio\AudioPrep;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

/*
| A person's own transcription key (since v1.6, P1.3, docs/API.md "Your own transcription key") and the default plans:
| Free transcribes only with the person's own Groq key (never the server's, never billed to it), or fails saying what
| to do; Pro and Business use the server's engines. Groq is faked: nothing leaves the test.
*/

uses(InteractsWithDeviceApi::class);

const OWN_KEY = 'gsk_own_person_key_ABCDEFGH1234567890_3f9a';

const SERVER_KEY = 'gsk_server_key_00000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    [$this->device, $this->token] = $this->enroll($this->user);
    Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => Plan::free()->id]);
    // No ffmpeg: Groq gets the whole file.
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return false;
        }
    });
});

function groqTakesKeys(array $good = [OWN_KEY]): void
{
    Http::fake(['api.groq.com/openai/v1/models' => fn (HttpRequest $r) => in_array(substr((string) $r->header('Authorization')[0], 7), $good, true)
        ? Http::response(['data' => [['id' => 'whisper-large-v3']]], 200) : Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);
}

describe('PUT and DELETE /api/me/transcription-key', function () {
    it('checks the key with Groq once, keeps it encrypted and never sends it back', function () {
        groqTakesKeys();
        $answer = $this->asToken($this->token)->putJson('/api/me/transcription-key', ['provider' => 'groq', 'key' => OWN_KEY])->assertOk()
            ->assertExactJson(['provider' => 'groq', 'set' => true, 'last4' => '3f9a']);

        expect($answer->getContent())->not->toContain(OWN_KEY);
        $raw = (string) DB::table('users')->where('id', $this->user->id)->value('transcription_key');
        expect($raw)->not->toContain(OWN_KEY)->not->toBe('')
            ->and($this->user->fresh()->transcription_key)->toBe(OWN_KEY)
            ->and($this->user->fresh()->toArray())->not->toHaveKey('transcription_key');
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.groq.com/openai/v1/models' && $r->hasHeader('Authorization', 'Bearer '.OWN_KEY));
        foreach (['/api/me/entitlements', '/api/account', '/api/config', '/api/security'] as $uri) {
            expect($this->asToken($this->token)->getJson($uri)->assertOk()->getContent())->not->toContain(OWN_KEY);
        }
        $this->asToken($this->token)->getJson('/api/me/entitlements')->assertJsonPath('transcription_key', ['provider' => 'groq', 'set' => true, 'last4' => '3f9a']);
        expect(AuditEvent::query()->pluck('summary')->implode(' '))->not->toContain(OWN_KEY);

        $this->asToken($this->token)->deleteJson('/api/me/transcription-key')->assertNoContent();
        expect($this->user->fresh()->transcription_key)->toBeNull();
        $this->asToken($this->token)->getJson('/api/me/entitlements')->assertJsonPath('transcription_key.set', false);
    });

    it('refuses a key Groq does not take, and says so when Groq cannot be asked', function () {
        groqTakesKeys([]);
        $this->asToken($this->token)->putJson('/api/me/transcription-key', ['provider' => 'groq', 'key' => OWN_KEY])->assertStatus(422)
            ->assertJsonPath('code', 'invalid_key')->assertJsonMissingPath('key');
        $this->asToken($this->token)->putJson('/api/me/transcription-key', ['provider' => 'groq', 'key' => 'not a key'])->assertStatus(422)->assertJsonPath('code', 'invalid_key');
        $this->asToken($this->token)->putJson('/api/me/transcription-key', ['provider' => 'openai', 'key' => OWN_KEY])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect($this->user->fresh()->transcription_key)->toBeNull();
    });

    it('says when Groq cannot check the key now, and keeps nothing', function () {
        Http::fake(['api.groq.com/*' => Http::response('', 503)]);
        $this->asToken($this->token)->putJson('/api/me/transcription-key', ['provider' => 'groq', 'key' => OWN_KEY])->assertStatus(502)->assertJsonPath('code', 'provider_error');
        expect($this->user->fresh()->transcription_key)->toBeNull();
    });

    it('takes 10 tries an hour per person', function () {
        groqTakesKeys([]);
        foreach (range(1, 10) as $i) {
            $this->asToken($this->token)->putJson('/api/me/transcription-key', ['provider' => 'groq', 'key' => OWN_KEY])->assertStatus(422);
        }
        $this->asToken($this->token)->putJson('/api/me/transcription-key', ['provider' => 'groq', 'key' => OWN_KEY])->assertStatus(429)->assertHeader('Retry-After');
    });
});

describe('a Free recording', function () {
    beforeEach(function () {
        app(CredentialVault::class)->put('groq', 'api_key', SERVER_KEY);
        app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_server_key_1234567890');
        app(SettingsRepository::class)->set('routing.final', ['en' => ['assemblyai', 'groq']]);
    });

    function startFinal(object $test): Transcript
    {
        $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => 'en']);
        $transcript = app(FinalTranscriptCoordinator::class)->start($session);
        dispatch_sync(new TranscribeFinalJob($transcript->id));

        return $transcript->refresh();
    }

    it('is transcribed with the person\'s own key through Groq, and nothing is billed to the server', function () {
        $this->user->forceFill(['transcription_key' => OWN_KEY, 'transcription_key_provider' => 'groq', 'transcription_key_last4' => '3f9a'])->save();
        $sent = [];
        Http::fake(['api.groq.com/openai/v1/audio/transcriptions' => function (HttpRequest $r) use (&$sent) {
            $sent[] = $r->header('Authorization')[0] ?? '';

            return Http::response(['task' => 'transcribe', 'language' => 'english', 'text' => 'Hello there.', 'segments' => [
                ['id' => 0, 'start' => 0.0, 'end' => 2.0, 'text' => 'Hello there.', 'avg_logprob' => -0.2, 'compression_ratio' => 1.2, 'no_speech_prob' => 0.01],
            ], 'words' => []], 200);
        }]);

        $transcript = startFinal($this);

        expect($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->provider)->toBe('groq')
            ->and($transcript->meta['own_key'] ?? false)->toBeTrue()
            ->and($sent)->not->toBeEmpty()->each->toBe('Bearer '.OWN_KEY);
        $row = UsageLedgerEntry::query()->withoutGlobalScopes()->where('transcript_id', $transcript->id)->firstOrFail();
        expect($row->billable)->toBeFalse()->and((float) $row->cost_usd)->toBe(0.0)->and($row->source)->toBe('own_key')->and($row->model)->toBe('own key');
    });

    it('fails with needs_transcription_key without a key, and sends nothing anywhere', function () {
        Http::fake();
        $transcript = startFinal($this);

        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('needs_transcription_key')
            ->and($transcript->error)->toBe('Add your free Groq key in Account → Transcription key');
        Http::assertNothingSent();
        $this->asToken($this->token)->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('error_code', 'needs_transcription_key');
    });

    it('uses the server\'s engines on Pro, and the trial minutes the admin gives on Free', function () {
        Http::fake(['api.assemblyai.com/*' => Http::response(['id' => 'job_1', 'status' => 'queued'], 200)]);
        Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]);
        expect(startFinal($this)->provider)->toBe('assemblyai');

        Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => Plan::free()->id]);
        Plan::free()->update(['trial_minutes' => 30]);
        $this->asToken($this->token)->getJson('/api/me/entitlements')->assertJsonPath('plan.trial_minutes_left', 30)
            ->assertJsonPath('engines', ['own_groq', 'on_device']);
        expect(startFinal($this)->meta['own_key'] ?? false)->toBeFalse();
    });
});

it('gives new sign-ups the Free plan: the free features, 2 devices, 1 GB, 30 days', function () {
    $user = app(SignUp::class)->create(['name' => 'New', 'email' => 'new@example.test', 'password' => 'correct-horse-9']);
    $plan = Workspace::query()->findOrFail($user->current_workspace_id)->plan;

    expect($plan->key)->toBe('free')->and($plan->is_default)->toBeTrue()->and($plan->max_devices)->toBe(2)
        ->and($plan->storage_bytes)->toBe(1024 ** 3)->and($plan->retention_days)->toBe(30)
        ->and(array_filter($plan->featureMap()))->toBe([]);
});

it('sets up the key on Profile → Transcription key in three steps, and refuses one Groq does not take', function () {
    Filament\Facades\Filament::setCurrentPanel('app');
    groqTakesKeys();
    $this->actingAs($this->user, 'web');
    $this->user->forceFill(['consent_seen_at' => now()])->save();

    $this->get('/app/settings/transcription-key')->assertOk()->assertSee('Transcription key')->assertSee('Transcripts on Free use your own free Groq key.');
    Livewire\Livewire::test(EditProfile::class, ['subpage' => 'transcription-key'])
        ->set('groqKey', 'gsk_wrong_key_000000000000000000')->call('saveGroqKey')
        ->assertSet('groqKeyError', "That key didn't work. Check it on console.groq.com and paste it again.");
    expect($this->user->fresh()->transcription_key)->toBeNull();

    Livewire\Livewire::test(EditProfile::class, ['subpage' => 'transcription-key'])
        ->set('groqKey', OWN_KEY)->call('saveGroqKey')
        ->assertNotified('Your Groq key is saved');
    expect($this->user->fresh()->transcription_key)->toBe(OWN_KEY);
    expect($this->get('/app/settings/transcription-key')->assertOk()->assertSee('Ending in 3f9a')->getContent())->not->toContain(OWN_KEY);
});

describe('re-transcribing a Free recording', function () {
    beforeEach(function () {
        app(CredentialVault::class)->put('groq', 'api_key', SERVER_KEY);
        app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_server_key_1234567890');
    });

    it('uses the person\'s own key exactly like the first run, and fails again without one', function () {
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => 'en']);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'text' => 'Old.', 'segments' => []]);

        $run = $this->asToken($this->token)->postJson("/api/sessions/{$session->id}/retranscribe", ['language' => 'ur'])->assertSuccessful()->json();
        $failed = Transcript::query()->findOrFail($run['transcript']['id']);
        expect($failed->status)->toBe(TranscriptStatus::Failed)->and($failed->error_code)->toBe('needs_transcription_key');

        $this->user->forceFill(['transcription_key' => OWN_KEY, 'transcription_key_provider' => 'groq', 'transcription_key_last4' => '3f9a'])->save();
        $sent = [];
        Http::fake(['api.groq.com/openai/v1/audio/transcriptions' => function (HttpRequest $r) use (&$sent) {
            $sent[] = $r->header('Authorization')[0] ?? '';

            return Http::response(['task' => 'transcribe', 'language' => 'urdu', 'text' => 'سلام', 'segments' => [
                ['id' => 0, 'start' => 0.0, 'end' => 1.0, 'text' => 'سلام', 'avg_logprob' => -0.2, 'compression_ratio' => 1.2, 'no_speech_prob' => 0.01],
            ], 'words' => []], 200);
        }]);
        $again = $this->asToken($this->token)->postJson("/api/sessions/{$session->id}/retranscribe", ['language' => 'ur'])->assertSuccessful()->json();
        $made = Transcript::query()->findOrFail($again['transcript']['id']);
        expect($made->status)->toBe(TranscriptStatus::Completed)->and($made->meta['own_key'] ?? false)->toBeTrue()
            ->and($sent)->not->toBeEmpty()->each->toBe('Bearer '.OWN_KEY);
    });
});
