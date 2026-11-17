<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CallRule;
use App\Models\CaptureSession;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\SignUp;
use App\Services\CallRules\CallRules;
use App\Services\Entitlements\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

/*
 * Call rules and the after-call choice (contract 2.1.0-a, docs/PLAN_CALL_RULES.md section 8): precedence, Ask me and
 * Use my default, the Free plan, the revision, the choice per recording, and today's behaviour for older accounts.
 */

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    [$this->device, $this->token] = $this->enroll($this->owner);
    $this->asToken($this->token);
    $this->configureAssemblyAi();
    Http::fake(['*' => Http::response(['id' => 'job_1', 'status' => 'queued', 'upload_url' => 'https://cdn.example/u'], 200)]);
});

/** Puts the device's workspace on a plan made of [$attributes]. */
function callRulesPlan(object $test, array $attributes): Plan
{
    $plan = Plan::query()->create(['key' => 'plan_'.uniqid(), 'name' => 'Test plan', ...$attributes]);
    Workspace::query()->whereKey($test->device->workspace_id)->update(['plan_id' => $plan->id]);
    app(Entitlements::class)->forget();

    return $plan;
}

function finalTranscriptOf(string $sessionId): ?object
{
    return DB::table('transcripts')->where('capture_session_id', $sessionId)->where('kind', TranscriptKind::Final->value)->first();
}

describe('rules', function () {
    it('lets a contact rule win over a group, a group over a source, a source over the default', function () {
        $this->putJson('/api/call-rules/default', ['action' => 'keep_audio'])->assertOk();
        $this->putJson('/api/call-rules/sources/zoom', ['action' => 'transcribe'])->assertOk();
        $this->putJson('/api/call-rules/groups/favourites', ['action' => 'dont_record'])->assertOk();
        $this->putJson('/api/call-rules/contacts', ['key' => 'TEL:+923001234567', 'name' => 'Ahmed', 'action' => 'ask'])->assertOk();

        $resolve = fn (array $q) => $this->getJson('/api/call-rules/resolve?'.http_build_query($q))->assertOk()->json();
        expect($resolve(['source' => 'zoom', 'group' => 'favourites', 'key' => 'tel:+923001234567']))->toBe(['action' => 'ask', 'by' => 'contact'])
            ->and($resolve(['source' => 'zoom', 'group' => 'favourites', 'key' => 'tel:+920000000000']))->toBe(['action' => 'dont_record', 'by' => 'group'])
            ->and($resolve(['source' => 'zoom']))->toBe(['action' => 'transcribe', 'by' => 'source'])
            ->and($resolve(['source' => 'slack']))->toBe(['action' => 'keep_audio', 'by' => 'default']);
    });

    it('stores "Ask me" as a rule and "Use my default" as no rule', function () {
        $this->putJson('/api/call-rules/default', ['action' => 'keep_audio'])->assertOk();
        $this->putJson('/api/call-rules/groups/unknown_numbers', ['action' => 'ask'])->assertOk()->assertJsonPath('groups.unknown_numbers', 'ask');
        expect($this->getJson('/api/call-rules/resolve?group=unknown_numbers')->json('action'))->toBe('ask');

        $this->deleteJson('/api/call-rules/groups/unknown_numbers')->assertOk()->assertJsonPath('groups.unknown_numbers', null);
        expect($this->getJson('/api/call-rules/resolve?group=unknown_numbers')->json())->toBe(['action' => 'keep_audio', 'by' => 'default']);
    });

    it('serves the whole document, keeps contacts in lowercase and refuses what it does not know', function () {
        $doc = $this->putJson('/api/call-rules/contacts', ['key' => 'WA:+923001234567', 'name' => 'Sara', 'action' => 'keep_audio'])->assertOk()->json();
        expect($doc['contacts'])->toHaveCount(1)->and($doc['contacts'][0])->toMatchArray(['key' => 'wa:+923001234567', 'name' => 'Sara', 'action' => 'keep_audio'])
            ->and($doc['default'])->toBe(['action' => 'transcribe', 'stored' => false])
            ->and($doc['short_calls'])->toBe(['keep' => true, 'seconds' => 30])
            ->and(array_keys($doc['groups']))->toBe(CallRules::GROUPS)->and(array_keys($doc['sources']))->toBe(CallRules::SOURCES);

        $this->putJson('/api/call-rules/default', ['action' => 'delete'])->assertStatus(422)->assertJsonPath('code', 'invalid_action');
        $this->putJson('/api/call-rules/contacts', ['key' => 'ahmed', 'action' => 'ask'])->assertStatus(422)->assertJsonPath('code', 'invalid_key');
        $this->putJson('/api/call-rules/groups/everyone', ['action' => 'ask'])->assertNotFound();
        $this->putJson('/api/call-rules/sources/fax', ['action' => 'ask'])->assertNotFound();

        $this->deleteJson('/api/call-rules/contacts?key=wa:%2B923001234567')->assertOk()->assertJsonPath('contacts', []);
    });

    it('bumps the revision on every change and carries it in GET /api/config', function () {
        expect($this->getJson('/api/config')->json('call_rules'))->toBe(['revision' => 0, 'default' => 'transcribe', 'short_calls_keep' => true]);

        $this->putJson('/api/call-rules/short-calls', ['keep' => false])->assertOk()->assertJsonPath('revision', 1);
        $this->putJson('/api/call-rules/sources/google_meet', ['action' => 'keep_audio'])->assertOk()->assertJsonPath('revision', 2);

        expect($this->getJson('/api/config')->json('call_rules'))->toBe(['revision' => 2, 'default' => 'transcribe', 'short_calls_keep' => false]);
    });

    it('refuses "Transcribe automatically" on a plan without it, and reads a stored one as Ask', function () {
        $this->putJson('/api/call-rules/sources/zoom', ['action' => 'transcribe'])->assertOk();
        callRulesPlan($this, ['features' => ['platform_transcription' => false, 'auto_transcribe' => false]]);

        $this->putJson('/api/call-rules/default', ['action' => 'transcribe'])->assertForbidden()
            ->assertJsonPath('code', 'feature_not_in_plan')->assertJsonPath('feature', 'auto_transcribe');
        $this->putJson('/api/call-rules/default', ['action' => 'keep_audio'])->assertOk();
        expect($this->getJson('/api/call-rules/resolve?source=zoom')->json())->toBe(['action' => 'ask', 'by' => 'source']);
    });

    it('starts a new account on Ask me without short calls, and leaves an older one as it was', function () {
        $user = app(SignUp::class)->create(['name' => 'New person', 'email' => 'new.person@example.test', 'password' => 'a-long-enough-password-1']);

        expect($user->fresh()->after_call_default)->toBe('ask')->and($user->fresh()->short_calls_keep)->toBeFalse()
            ->and($this->owner->fresh()->after_call_default)->toBeNull()->and($this->owner->fresh()->short_calls_keep)->toBeNull();
    });
});

describe('the choice for one recording', function () {
    it('keeps transcribing every upload for a person with no stored default (today\'s behaviour)', function () {
        $flow = $this->uploadRecording(Fixtures::audio(3000));

        expect(finalTranscriptOf($flow['session']))->not->toBeNull()
            ->and(CaptureSession::query()->find($flow['session'])->choice)->toBeNull();
    });

    it('keeps the audio only, with nothing sent to an engine, when the default asks and no choice came', function () {
        $this->owner->forceFill(['after_call_default' => 'ask'])->save();

        $flow = $this->uploadRecording(Fixtures::audio(3000));
        $session = CaptureSession::query()->find($flow['session']);

        expect(finalTranscriptOf($flow['session']))->toBeNull()
            ->and($session->status->value)->toBe('completed')
            ->and([$session->choice, $session->choice_by, $session->needs_action])->toBe(['keep_audio', 'default', true]);
        $item = $this->getJson("/api/sessions/{$flow['session']}")->assertOk()->json();
        expect($item['choice'])->toMatchArray(['value' => 'keep_audio', 'by' => 'default', 'needs_action' => true])
            ->and($item['retention'])->toMatchArray(['audio_only' => true, 'protected' => false]);
    });

    it('takes the choice a rule made with the create, and transcribes later on request', function () {
        $flow = $this->uploadRecording(Fixtures::audio(3000), ['choice' => 'keep_audio', 'choice_by' => 'rule']);
        expect(finalTranscriptOf($flow['session']))->toBeNull()
            ->and(CaptureSession::query()->find($flow['session'])->choice_by)->toBe('rule');

        $this->postJson("/api/sessions/{$flow['session']}/choice", ['choice' => 'transcribe'])->assertOk()
            ->assertJsonPath('data.choice.value', 'transcribe')->assertJsonPath('data.retention.audio_only', false);
        expect(finalTranscriptOf($flow['session']))->not->toBeNull();
    });

    it('refuses Keep audio once a transcript exists, Don\'t record for a recording, and deletes on Delete', function () {
        $flow = $this->uploadRecording(Fixtures::audio(3000));
        DB::table('transcripts')->where('capture_session_id', $flow['session'])->update(['status' => TranscriptStatus::Completed->value]);

        $this->postJson("/api/sessions/{$flow['session']}/choice", ['choice' => 'keep_audio'])->assertStatus(409)->assertJsonPath('code', 'already_transcribed');
        $this->postJson("/api/sessions/{$flow['session']}/choice", ['choice' => 'dont_record'])->assertStatus(422)->assertJsonPath('code', 'invalid_choice');
        $this->postJson("/api/sessions/{$flow['session']}/choice", ['choice' => 'delete'])->assertOk()->assertJsonPath('data.deleted', true);
        expect(CaptureSession::query()->find($flow['session']))->toBeNull();
    });

    it('counts what people chose, per plan, without content', function () {
        $this->owner->forceFill(['after_call_default' => 'keep_audio'])->save();
        $kept = $this->uploadRecording(Fixtures::audio(3000));
        $this->postJson("/api/sessions/{$kept['session']}/choice", ['choice' => 'delete'])->assertOk();

        expect(DB::table('call_choice_log')->orderBy('id')->pluck('outcome')->all())->toBe(['kept', 'deleted'])
            ->and(array_keys((array) DB::table('call_choice_log')->first()))->toBe(['id', 'workspace_id', 'user_id', 'plan_key', 'outcome', 'by', 'created_at']);
    });
});

describe('Transcribe many', function () {
    it('shows the minutes and minutes left first, then starts all or nothing', function () {
        callRulesPlan($this, ['monthly_minutes' => 10]);
        $a = $this->uploadRecording(Fixtures::audio(3000), ['choice' => 'keep_audio', 'duration_ms' => 120000])['session'];
        $b = $this->uploadRecording(Fixtures::audio(3001), ['choice' => 'keep_audio', 'duration_ms' => 300000])['session'];

        $estimate = $this->postJson('/api/sessions/transcribe/estimate', ['ids' => [$a, $b]])->assertOk()->json();
        expect($estimate)->toMatchArray(['count' => 2, 'minutes' => 7.0, 'minutes_left' => 10.0, 'enough' => true]);

        $this->postJson('/api/sessions/transcribe', ['ids' => [$a, $b], 'max_minutes' => 2.0])->assertStatus(409)->assertJsonPath('code', 'estimate_changed');
        $this->postJson('/api/sessions/transcribe', ['ids' => [$a, $b], 'max_minutes' => 7.0])->assertOk()->assertJsonPath('started', 2);
        expect(finalTranscriptOf($a))->not->toBeNull()->and(finalTranscriptOf($b))->not->toBeNull();
    });

    it('starts none when the minutes do not fit', function () {
        callRulesPlan($this, ['monthly_minutes' => 3]);
        $a = $this->uploadRecording(Fixtures::audio(3000), ['choice' => 'keep_audio', 'duration_ms' => 300000])['session'];

        $this->postJson('/api/sessions/transcribe', ['ids' => [$a], 'max_minutes' => 5.0])->assertForbidden()->assertJsonPath('code', 'limit_reached');
        expect(finalTranscriptOf($a))->toBeNull();
    });
});

describe('the person\'s own audio retention', function () {
    it('shortens the plan\'s days, never lengthens them, and allows Never only on a plan that does', function () {
        callRulesPlan($this, ['audio_retention_days' => 30]);

        $this->getJson('/api/account/privacy')->assertOk()->assertJsonPath('audio_retention', [
            'choice' => 'plan', 'days' => 30, 'allowance_days' => 30, 'options' => [7, 14, 30], 'never_allowed' => false,
        ]);
        $this->putJson('/api/account/privacy', ['audio_retention_choice' => 14])->assertOk()->assertJsonPath('audio_retention.days', 14);
        $this->putJson('/api/account/privacy', ['audio_retention_choice' => 60])->assertStatus(422)->assertJsonPath('code', 'above_plan');
        $this->putJson('/api/account/privacy', ['audio_retention_choice' => 'never'])->assertStatus(422)->assertJsonPath('code', 'never_not_allowed');
        $this->putJson('/api/account/privacy', ['audio_retention_choice' => 13])->assertStatus(422)->assertJsonPath('code', 'invalid_choice');

        callRulesPlan($this, ['audio_retention_days' => null]);
        $this->putJson('/api/account/privacy', ['audio_retention_choice' => 'never'])->assertOk()->assertJsonPath('audio_retention.days', null);
        expect(CallRule::query()->count())->toBe(0);
    });

    it('tells each audio-only recording its days left, and none for a starred one', function () {
        callRulesPlan($this, ['audio_retention_days' => 30]);
        $id = $this->uploadRecording(Fixtures::audio(3000), ['choice' => 'keep_audio'])['session'];
        CaptureSession::query()->whereKey($id)->update(['choice_at' => now()->subDays(12)]);

        expect($this->getJson("/api/sessions/{$id}")->json('retention'))->toMatchArray(['audio_only' => true, 'days_left' => 18, 'protected' => false]);
        $this->patchJson("/api/sessions/{$id}", ['pinned' => true])->assertOk()
            ->assertJsonPath('data.retention.starred', true)->assertJsonPath('data.retention.days_left', null)->assertJsonPath('data.retention.protected', true);
    });
});
