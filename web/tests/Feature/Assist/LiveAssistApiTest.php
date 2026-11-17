<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Filament\Admin\Pages\ApiCredentials;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Workspace;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Live Assist's server API (v1.9 L1, docs/API_ASSIST.md 1.9.0-f): preps, sessions with the relay token, turns, the end
 * and its report. Everything is behind the live_assist flag, only the device's own person's, and another workspace's
 * ids answer 404.
 */

beforeEach(fn () => assistRelaySetUp());

describe('the flag', function () {
    it('hides every route from a person the live_assist flag is off for', function () {
        $device = assistPerson('pro', flag: false);
        $api = $this->api(Fx::token($device));
        $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        foreach ([['GET', '/api/assist/preps'], ['POST', '/api/assist/preps'], ['GET', "/api/assist/preps/{$id}"], ['POST', "/api/assist/preps/{$id}/arm"],
            ['POST', '/api/assist/sessions'], ['GET', '/api/assist/sessions'], ['POST', "/api/assist/sessions/{$id}/token"], ['POST', "/api/assist/sessions/{$id}/turns"],
            ['POST', "/api/assist/sessions/{$id}/end"], ['GET', "/api/assist/sessions/{$id}/report"]] as [$method, $uri]) {
            $api->json($method, $uri, ['meeting_type' => 'general', 'platform' => 'desktop'])->assertNotFound()->assertJsonPath('code', 'not_found');
        }
        expect(AssistPrep::query()->withoutGlobalScopes()->count())->toBe(0)->and(AssistSession::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('the relay pins', function () {
    it('sends the relay host\'s pins in the config while live_assist is on for the person, and never otherwise', function () {
        app(SettingsRepository::class)->set('relay.pins', ['sha256/AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=', 'sha256/AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=', 'not-a-pin']);
        $on = $this->api(Fx::token(assistPerson()))->getJson('/api/config')->assertOk();
        expect($on->json('relay_pins'))->toBe(['sha256/AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=', 'sha256/AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI='])->and($on->json('ui_flags.live_assist'))->toBeTrue();

        $off = $this->api(Fx::token(assistPerson(flag: false)))->getJson('/api/config')->assertOk();
        expect($off->json())->not->toHaveKey('relay_pins')->and($off->json('ui_flags.live_assist'))->toBeFalse();
    });

    it('lets the super admin keep the relay pins next to its address, valid ones only', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(ApiCredentials::class)
            ->set('data.relay_pins', ['sha256/AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='])->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->relayPins())->toBe(['sha256/AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=']);
        Livewire::test(ApiCredentials::class)->assertSet('data.relay_pins', ['sha256/AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='])
            ->set('data.relay_pins', ['sha256/short'])->call('save')->assertHasErrors();
        expect(app(SettingsRepository::class)->relayPins())->toBe(['sha256/AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=']);
    });
});

describe('abilities', function () {
    it('needs library:read to read, sessions:write to write and stt:token to start or refresh', function () {
        $device = assistPerson();
        // One token per ability; each request names the token it is sent with.
        $as = fn (string $ability) => $this->api(Fx::token($device, [$ability]));

        $as('sessions:write')->getJson('/api/assist/preps')->assertForbidden();
        $as('library:read')->postJson('/api/assist/preps', ['meeting_type' => 'general'])->assertForbidden();
        $as('sessions:write')->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])->assertForbidden();
        $as('library:read')->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])->assertForbidden();
        expect(AssistSession::query()->withoutGlobalScopes()->count())->toBe(0);

        $as('library:read')->getJson('/api/assist/preps')->assertOk();
        $as('sessions:write')->postJson('/api/assist/preps', ['meeting_type' => 'general'])->assertCreated();
        $id = $as('stt:token')->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])->assertCreated()->json('session.id');
        $as('stt:token')->postJson("/api/assist/sessions/{$id}/token")->assertOk();
        $as('library:read')->postJson("/api/assist/sessions/{$id}/token")->assertForbidden();
        $as('stt:token')->postJson("/api/assist/sessions/{$id}/turns", ['turns' => []])->assertForbidden();
        $as('library:read')->postJson("/api/assist/sessions/{$id}/end")->assertForbidden();
        $as('sessions:write')->postJson("/api/assist/sessions/{$id}/end")->assertOk();
    });
});

describe('preps', function () {
    it('saves a prep with its defaults, keeps its words encrypted and lists it by status', function () {
        $api = $this->api(Fx::token(assistPerson()));

        $prep = $api->postJson('/api/assist/preps', ['meeting_type' => 'interview', 'title' => 'Interview at Acme', 'counterpart' => 'Sara Khan',
            'goals' => 'Land the role', 'scheduled_at' => '2026-12-01T10:00:00Z'])->assertCreated()
            ->assertJsonPath('hint_level', 'outline')->assertJsonPath('mode', 'balanced')->assertJsonPath('language_help', 'off')
            ->assertJsonPath('status', 'scheduled')->assertJsonPath('title', 'Interview at Acme')->json();
        expect((string) DB::table('assist_preps')->where('id', $prep['id'])->value('goals'))->not->toContain('Land the role');
        expect($api->postJson('/api/assist/preps', ['meeting_type' => 'boss'])->assertCreated()->json('hint_level'))->toBe('full');

        $api->patchJson("/api/assist/preps/{$prep['id']}", ['mode' => 'fast', 'goals' => null, 'language_help' => 'roman_urdu'])->assertOk()
            ->assertJsonPath('mode', 'fast')->assertJsonPath('goals', null)->assertJsonPath('language_help', 'roman_urdu');
        expect($api->getJson('/api/assist/preps?status=scheduled')->assertOk()->json('data'))->toHaveCount(1);
        expect($api->getJson('/api/assist/preps')->json('data'))->toHaveCount(2);

        $api->postJson('/api/assist/preps', ['meeting_type' => 'party'])->assertStatus(422);
        $api->postJson('/api/assist/preps', ['meeting_type' => 'general', 'goals' => str_repeat('a', 4001)])->assertStatus(422);
    });

    it('attaches the workspace\'s project files, arms a prep and deletes it with its words', function () {
        $device = assistPerson();
        $api = $this->api(Fx::token($device));
        $project = Fx::client($device->user);
        $file = ClientFile::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'client_id' => $project->id,
            'name' => 'Scope.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'ready', 'text' => str_repeat('word ', 100)]);
        $id = $api->postJson('/api/assist/preps', ['meeting_type' => 'existing_client', 'project_id' => $project->id])->assertCreated()->json('id');

        $api->postJson("/api/assist/preps/{$id}/documents", ['file_ids' => [$file->id]])->assertOk()
            ->assertJsonPath('documents.0.file_id', $file->id)->assertJsonPath('documents.0.tokens', 125);
        $this->freezeTime();
        $api->postJson("/api/assist/preps/{$id}/arm")->assertOk()->assertJsonPath('armed_until', now()->addMinutes(10)->toAtomString());
        expect($api->getJson("/api/assist/preps/{$id}")->json('status'))->toBe('armed');
        $this->travel(11)->minutes();
        expect($api->getJson("/api/assist/preps/{$id}")->json('status'))->toBe('cancelled');
        $api->deleteJson("/api/assist/preps/{$id}/documents/{$file->id}")->assertNoContent();
        $api->deleteJson("/api/assist/preps/{$id}/documents/{$file->id}")->assertNotFound();

        $api->deleteJson("/api/assist/preps/{$id}")->assertNoContent();
        $api->getJson("/api/assist/preps/{$id}")->assertNotFound();
    });
});

describe('another workspace', function () {
    it('answers 404 for a teammate\'s project and its files in the same workspace (projects are private)', function () {
        $alice = assistPerson();
        $bob = Fx::user();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($alice->workspace_id), $bob);
        $bobProject = Client::query()->withoutGlobalScopes()->create(['workspace_id' => $alice->workspace_id, 'user_id' => $bob->id, 'name' => 'BOB-PROJECT', 'color' => '#8B5CF6']);
        $bobFile = ClientFile::query()->create(['workspace_id' => $alice->workspace_id, 'user_id' => $bob->id, 'client_id' => $bobProject->id,
            'name' => 'Bob.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'ready', 'text' => 'x']);
        $api = $this->api(Fx::token($alice));
        $prep = $api->postJson('/api/assist/preps', ['meeting_type' => 'general'])->json('id');

        $api->postJson('/api/assist/preps', ['meeting_type' => 'general', 'project_id' => $bobProject->id])->assertNotFound();
        $api->patchJson("/api/assist/preps/{$prep}", ['project_id' => $bobProject->id])->assertNotFound();
        $api->postJson("/api/assist/preps/{$prep}/documents", ['file_ids' => [$bobFile->id]])->assertNotFound();
        expect(AssistPrep::query()->withoutGlobalScopes()->find($prep)->client_id)->toBeNull();
    });

    it('answers 404 for another person\'s prep, session, project, file and recording', function () {
        $bob = assistPerson();
        $bobApi = $this->api(Fx::token($bob));
        $bobPrep = $bobApi->postJson('/api/assist/preps', ['meeting_type' => 'general', 'title' => 'BOB-SECRET'])->json('id');
        $bobSession = $bobApi->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])->json('session.id');
        $bobProject = Fx::client($bob->user, 'BOB-PROJECT');
        $bobFile = ClientFile::query()->create(['workspace_id' => $bob->workspace_id, 'user_id' => $bob->user_id, 'client_id' => $bobProject->id,
            'name' => 'Bob.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'ready', 'text' => 'x']);
        $bobRecording = Fx::session($bob->user, $bob, ['status' => SessionStatus::Recording]);

        $alice = $this->api(Fx::token(assistPerson()));
        $myPrep = $alice->postJson('/api/assist/preps', ['meeting_type' => 'general'])->json('id');
        foreach ([
            ['GET', "/api/assist/preps/{$bobPrep}", []], ['PATCH', "/api/assist/preps/{$bobPrep}", ['title' => 'x']], ['DELETE', "/api/assist/preps/{$bobPrep}", []],
            ['POST', "/api/assist/preps/{$bobPrep}/arm", []], ['POST', "/api/assist/preps/{$bobPrep}/documents", ['file_ids' => [$bobFile->id]]],
            ['POST', "/api/assist/preps/{$myPrep}/documents", ['file_ids' => [$bobFile->id]]],
            ['PATCH', "/api/assist/preps/{$myPrep}", ['project_id' => $bobProject->id]],
            ['GET', "/api/assist/sessions/{$bobSession}", []], ['POST', "/api/assist/sessions/{$bobSession}/token", []],
            ['POST', "/api/assist/sessions/{$bobSession}/turns", ['turns' => [['client_ref' => 'a', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x']]]],
            ['POST', "/api/assist/sessions/{$bobSession}/end", []], ['GET', "/api/assist/sessions/{$bobSession}/report", []],
            ['DELETE', "/api/assist/sessions/{$bobSession}", []], ['POST', "/api/assist/sessions/{$bobSession}/suggestions/s1/action", ['action' => 'used']],
            ['POST', '/api/assist/sessions', ['platform' => 'desktop', 'prep_id' => $bobPrep]],
            ['POST', '/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast', 'recording_session_id' => $bobRecording->id]],
        ] as [$method, $uri, $body]) {
            $answer = $alice->json($method, $uri, $body);
            expect($answer->status())->toBe(404, "{$method} {$uri}")
                ->and((string) $answer->getContent())->not->toContain('BOB-SECRET')->not->toContain('BOB-PROJECT');
        }
        expect($alice->getJson('/api/assist/preps')->json('data'))->toHaveCount(1)
            ->and($alice->getJson('/api/assist/sessions')->json('data'))->toBe([])
            ->and(AssistSession::query()->withoutGlobalScopes()->find($bobSession)->status)->toBe('active');
    });
});

describe('starting a session', function () {
    it('starts a live session with a relay token the relay accepts, from the relay setting', function () {
        $device = assistPerson('pro');
        $recording = Fx::session($device->user, $device, ['status' => SessionStatus::Recording]);
        // Without the captions' provider a session with a running recording still starts: the recording has its own.
        app(CredentialVault::class)->forget('soniox', 'api_key');
        $this->freezeTime();

        $answer = $this->api(Fx::token($device))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'balanced', 'recording_session_id' => $recording->id])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('session.mode', 'balanced')->assertJsonPath('session.practice', false)->assertJsonPath('session.auto_saved', false)
            ->assertJsonPath('relay.url', 'wss://relay.example.com/v1/assist')->assertJsonPath('stt', null)
            ->assertJsonPath('plan.modes', ['fast', 'balanced'])->assertJsonPath('plan.seconds_left', 36000);
        $claims = relayClaims($answer->json('relay.token'));

        expect($claims)->toMatchArray([
            'iss' => 'echoledger', 'aud' => 'assist-relay', 'sub' => (string) $device->user_id, 'ws' => (string) $device->workspace_id,
            'sid' => $answer->json('session.id'), 'plan' => 'pro', 'modes' => ['fast', 'balanced'],
            'models' => ['claude-haiku-5-5', 'openai/gpt-oss-120b', 'claude-sonnet-5-5'],
            'budget' => ['seconds_left' => 36000, 'cents_left' => 1500], 'practice' => false, 'iat' => now()->getTimestamp(),
        ])->and($claims['exp'] - $claims['iat'])->toBe(900)
            ->and($claims['jti'])->toMatch('/^[0-9A-Z]{26}$/')
            ->and($answer->json('relay.expires_at'))->toBe(now()->addSeconds(900)->toAtomString());
        expect(AssistSession::query()->withoutGlobalScopes()->find($answer->json('session.id'))->capture_session_id)->toBe($recording->id);
    });

    it('gives a Practice session the fast mode on every plan, Free included, within the Practice time', function (string $plan, string $claim) {
        $device = assistPerson($plan);

        $answer = $this->api(Fx::token($device))->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true, 'mode' => 'max'])
            ->assertCreated()->assertJsonPath('session.mode', 'fast')->assertJsonPath('session.practice', true)->assertJsonPath('plan.modes', ['fast']);

        expect(relayClaims($answer->json('relay.token')))->toMatchArray(['plan' => $claim, 'modes' => ['fast'], 'practice' => true,
            'models' => ['claude-haiku-5-5', 'openai/gpt-oss-120b']]);
    })->with([['free', 'free'], ['pro', 'pro'], ['business', 'ultra']]);

    it('refuses a live session on Free and a mode the plan leaves out, naming the lowest plan', function () {
        $this->api(Fx::token(assistPerson('free')))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])
            ->assertForbidden()->assertJsonPath('code', 'feature_not_in_plan')->assertJsonPath('feature', 'live_assist')->assertJsonPath('lowest_plan', 'pro');
        $this->api(Fx::token(assistPerson('pro')))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'deep'])
            ->assertForbidden()->assertJsonPath('feature', 'assist_mode')->assertJsonPath('mode', 'deep')->assertJsonPath('lowest_plan', 'business');
        $ultra = $this->api(Fx::token(assistPerson('business')))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'max'])->assertCreated();
        expect(relayClaims($ultra->json('relay.token'))['modes'])->toBe(['fast', 'balanced', 'deep', 'max']);
        expect(AssistSession::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('starts from a prep: auto-saved, the prep\'s mode, and the prep is done', function () {
        $api = $this->api(Fx::token(assistPerson('pro')));
        $prep = $api->postJson('/api/assist/preps', ['meeting_type' => 'existing_client', 'mode' => 'fast', 'language_help' => 'urdu'])->json('id');
        $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'prep_id' => $prep])->assertCreated()
            ->assertJsonPath('session.auto_saved', true)->assertJsonPath('session.mode', 'fast')
            ->assertJsonPath('stt.provider', 'soniox')->assertJsonPath('stt.token', 'temp:abc')->assertJsonPath('stt.translate_other', true);
        expect($api->getJson("/api/assist/preps/{$prep}")->json('status'))->toBe('done');
    });

    it('allows one assist session at a time, live or Practice, and ends one whose app stopped refreshing its token', function () {
        $device = assistPerson('pro');
        $api = $this->api(Fx::token($device));
        $practice = $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->assertCreated()->json('session.id');
        // A second Practice, and a live session next to Practice, would spend the month's allowance twice. The answer
        // names the session on, so the app can end it and start again (1.9.0-k).
        $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->assertStatus(409)->assertJsonPath('code', 'session_active')
            ->assertJsonPath('session_id', $practice)->assertJsonPath('practice', true)->assertJsonPath('device_id', $device->id);
        $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])->assertStatus(409)->assertJsonPath('code', 'session_active');
        // From another device of the same person too.
        $this->api(Fx::token(Fx::device($device->user, ['workspace_id' => $device->workspace_id])))
            ->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])->assertStatus(409);
        $api->postJson("/api/assist/sessions/{$practice}/end")->assertOk();

        $first = $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])->assertCreated()->json('session.id');
        $api->postJson('/api/assist/sessions', ['platform' => 'android', 'mode' => 'fast'])->assertStatus(409)->assertJsonPath('code', 'session_active');
        $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->assertStatus(409);

        $this->travel(21)->minutes();
        $api->postJson('/api/assist/sessions', ['platform' => 'android', 'mode' => 'fast'])->assertCreated();
        $stale = AssistSession::query()->withoutGlobalScopes()->find($first);
        // It ended where its last token did, so the time after counts for nothing.
        expect($stale->status)->toBe('ended')->and($stale->seconds_used)->toBe(900);
    });

    it('allows one session per person across all their workspaces, and asks the captions\' provider nothing when refusing', function () {
        $device = assistPerson('pro');
        $live = $this->api(Fx::token($device))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])->assertCreated()->json('session.id');
        // The same person in a second workspace, signed in there on another device.
        $user = $device->user->fresh();
        $team = app(Workspaces::class)->createFor($user, 'Second team');
        app(Workspaces::class)->switchTo($user, $team);
        $there = Fx::device($user->fresh(), ['workspace_id' => $team->id]);
        Http::swap(new HttpFactory);
        Http::fake(['api.soniox.com/*' => Http::response(['api_key' => 'temp:abc', 'expires_at' => '2026-12-01T10:10:00Z'])]);

        $this->api(Fx::token($there))->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])
            ->assertStatus(409)->assertJsonPath('code', 'session_active')->assertJsonPath('session_id', $live)
            ->assertJsonPath('practice', false)->assertJsonPath('device_id', $device->id);
        Http::assertNothingSent();
        expect(AssistSession::query()->withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('answers 503 while the relay is not set up, and creates nothing', function () {
        app(SettingsRepository::class)->set('relay.url', null);
        $this->api(Fx::token(assistPerson()))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])
            ->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
        expect(AssistSession::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses Practice, and a live session without a recording, while the captions cannot be given, and creates nothing', function () {
        $device = assistPerson('pro');
        $api = $this->api(Fx::token($device));
        app(CredentialVault::class)->forget('soniox', 'api_key');

        $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])
            ->assertStatus(503)->assertJsonPath('code', 'provider_not_configured')->assertJsonPath('message', 'Live captions are not set up on the server yet.');
        $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])
            ->assertStatus(503)->assertJsonPath('message', 'Live captions are not set up on the server yet.');
        expect(AssistSession::query()->withoutGlobalScopes()->count())->toBe(0);

        // The provider set up but not answering: the same.
        app(CredentialVault::class)->put('soniox', 'api_key', 'snx-test-key-0123456789');
        Http::swap(new HttpFactory);
        Http::fake(fn () => Http::response('down', 500));
        $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->assertStatus(503);
        // With a running recording it starts without captions: the recording has its own.
        $recording = Fx::session($device->user, $device, ['status' => SessionStatus::Recording]);
        $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast', 'recording_session_id' => $recording->id])
            ->assertCreated()->assertJsonPath('stt', null);
    });

    it('never fails a token refresh because the captions are down: stt is null and the session goes on (1.9.0-j)', function () {
        $api = $this->api(Fx::token(assistPerson('pro')));
        $id = $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->assertCreated()->assertJsonPath('stt.token', 'temp:abc')->json('session.id');

        Http::swap(new HttpFactory);
        Http::fake(fn () => Http::response('down', 500));
        $refresh = $api->postJson("/api/assist/sessions/{$id}/token")->assertOk()->assertJsonPath('stt', null);
        expect(relayClaims($refresh->json('relay.token'))['sid'])->toBe($id);
        app(CredentialVault::class)->forget('soniox', 'api_key');
        $api->postJson("/api/assist/sessions/{$id}/token")->assertOk()->assertJsonPath('stt', null);
        $api->getJson("/api/assist/sessions/{$id}")->assertJsonPath('status', 'active');

        // Back up: the next refresh has captions again.
        app(CredentialVault::class)->put('soniox', 'api_key', 'snx-test-key-0123456789');
        Http::swap(new HttpFactory);
        Http::fake(['api.soniox.com/*' => Http::response(['api_key' => 'temp:back', 'expires_at' => '2026-12-01T10:10:00Z'])]);
        $api->postJson("/api/assist/sessions/{$id}/token")->assertOk()->assertJsonPath('stt.token', 'temp:back');
    });
});

describe('the budget', function () {
    it('refuses a start once the month\'s assist time is used, with the fallback the apps show', function () {
        $free = assistPerson('free');
        AssistSession::query()->create(['workspace_id' => $free->workspace_id, 'user_id' => $free->user_id, 'mode' => 'fast', 'practice' => true,
            'platform' => 'desktop', 'status' => 'ended', 'started_at' => now()->subMinutes(40), 'ended_at' => now()->subMinutes(10), 'seconds_used' => 1800]);
        $this->api(Fx::token($free))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])
            ->assertStatus(402)->assertJsonPath('code', 'budget_exhausted')->assertJsonPath('fallback', 'none');

        $pro = assistPerson('pro');
        AssistSession::query()->create(['workspace_id' => $pro->workspace_id, 'user_id' => $pro->user_id, 'mode' => 'fast', 'practice' => false,
            'platform' => 'desktop', 'status' => 'ended', 'started_at' => now()->subHours(11), 'ended_at' => now()->subHour(), 'seconds_used' => 36000]);
        $this->api(Fx::token($pro))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])
            ->assertStatus(402)->assertJsonPath('fallback', 'captions_only');
    });

    it('refuses a start once the month\'s model budget is spent, and counts what is left into the token', function () {
        $device = assistPerson('pro');
        $api = $this->api(Fx::token($device));
        $id = $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])->json('session.id');
        AssistUsage::query()->create(['assist_session_id' => $id, 'workspace_id' => $device->workspace_id, 'user_id' => $device->user_id,
            'suggestion_id' => 's-1', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'fast', 'kind' => 'answer', 'cost_usd' => 10.25, 'occurred_at' => now()]);

        // A refresh never refuses: the relay steps down to captions when nothing is left.
        expect(relayClaims($api->postJson("/api/assist/sessions/{$id}/token")->assertOk()->json('relay.token'))['budget']['cents_left'])->toBe(475);
        $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
        AssistUsage::query()->where('suggestion_id', 's-1')->update(['cost_usd' => 15.00]);
        $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])->assertStatus(402)->assertJsonPath('fallback', 'captions_only');
    });
});

describe('a session', function () {
    it('takes turns once each, refreshes its token, ends with a report, and refuses all three after the end', function () {
        $api = $this->api(Fx::token(assistPerson('pro')));
        $id = $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])->json('session.id');
        $turns = [
            ['client_ref' => 't1', 'channel' => 'other', 'start_ms' => 81200, 'end_ms' => 86400, 'text' => 'What is your notice period?', 'language' => 'en', 'is_question' => true],
            ['client_ref' => 't2', 'channel' => 'me', 'start_ms' => 87000, 'end_ms' => 90000, 'text' => 'One month.'],
        ];

        $api->postJson("/api/assist/sessions/{$id}/turns", ['turns' => $turns])->assertOk()->assertExactJson(['accepted' => 2]);
        $api->postJson("/api/assist/sessions/{$id}/turns", ['turns' => $turns])->assertOk()->assertExactJson(['accepted' => 0]);
        expect(AssistTurn::query()->where('assist_session_id', $id)->count())->toBe(2)
            ->and((string) DB::table('assist_turns')->where('client_ref', 't1')->value('text'))->not->toContain('notice period');
        $first = relayClaims($api->postJson("/api/assist/sessions/{$id}/token")->assertOk()->json('relay.token'));
        $second = relayClaims($api->postJson("/api/assist/sessions/{$id}/token")->assertOk()->json('relay.token'));
        expect($second['jti'])->not->toBe($first['jti'])->and($second['sid'])->toBe($id);
        $api->getJson("/api/assist/sessions/{$id}/report")->assertNotFound()->assertJsonPath('code', 'report_not_ready');
        $api->postJson("/api/assist/sessions/{$id}/suggestions/sug-1/action", ['action' => 'used'])->assertNoContent();
        $api->postJson("/api/assist/sessions/{$id}/suggestions/sug-1/action", ['action' => 'eaten'])->assertStatus(422);

        $api->postJson("/api/assist/sessions/{$id}/end")->assertOk()->assertExactJson(['report' => 'queued']);
        foreach (["/api/assist/sessions/{$id}/end", "/api/assist/sessions/{$id}/token", "/api/assist/sessions/{$id}/turns"] as $uri) {
            $api->postJson($uri, ['turns' => $turns])->assertStatus(409)->assertJsonPath('code', 'session_ended');
        }
        $report = $api->getJson("/api/assist/sessions/{$id}/report")->assertOk();
        expect($report->json('qa.0'))->toMatchArray(['at_ms' => 81200, 'question' => 'What is your notice period?', 'answer' => 'One month.'])
            ->and($report->json('turns'))->toBe(2);
        expect($api->getJson('/api/assist/sessions')->json('data.0'))->toMatchArray(['id' => $id, 'status' => 'ended', 'report' => 'ready']);
    });

    it('deletes a session with every word of it', function () {
        $api = $this->api(Fx::token(assistPerson('pro')));
        $id = $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])->json('session.id');
        $api->postJson("/api/assist/sessions/{$id}/turns", ['turns' => [['client_ref' => 'a', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 10, 'text' => 'Hello']]]);

        $api->deleteJson("/api/assist/sessions/{$id}")->assertNoContent();
        $api->getJson("/api/assist/sessions/{$id}")->assertNotFound();
        expect(AssistTurn::query()->where('assist_session_id', $id)->count())->toBe(0);
    });
});
