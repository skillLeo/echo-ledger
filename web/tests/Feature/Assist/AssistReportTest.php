<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Jobs\MakeAssistReportJob;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Models\ClientMemoryItem;
use App\Models\Device;
use App\Models\UsageLedgerEntry;
use App\Services\Assist\AssistPlans;
use App\Services\Assist\AssistReport;
use App\Services\Assist\ClaudeAssistReportAi;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * The post-call report (v1.9 L7, docs/PLAN_V1_9.md L7): the Q&A table, coaching, the model's words billed to the right
 * allowance, action items into the project's memory, and what is left without the model.
 */

beforeEach(fn () => assistRelaySetUp());

/** Posts a signed relay request (as services/assist-relay/src/internal.rs signs it). */
function reportRelay(object $test, string $path, array $body): TestResponse
{
    $raw = (string) json_encode($body);

    return $test->call('POST', $path, [], [], [], test()->transformHeadersToServerVars(relayHeaders('POST', $path, $raw)), $raw);
}

/** A Practice interview of three questions: two answered (the second with a hint replaced once), the third not. */
function practiceSession(object $test, object $api): string
{
    $id = $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->assertCreated()->json('session.id');
    $api->postJson("/api/assist/sessions/{$id}/turns", ['turns' => [
        ['client_ref' => 'pq-a-1', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 4000, 'text' => 'Tell me about yourself.', 'is_question' => true],
        ['client_ref' => 'm-1', 'channel' => 'me', 'start_ms' => 5000, 'end_ms' => 20000, 'text' => 'Um I am a Laravel developer, you know, for three years.'],
        ['client_ref' => 'm-2', 'channel' => 'me', 'start_ms' => 21000, 'end_ms' => 30000, 'text' => 'I build APIs.'],
        ['client_ref' => 'pq-a-2', 'channel' => 'other', 'start_ms' => 31000, 'end_ms' => 35000, 'text' => 'What salary do you expect?', 'is_question' => true],
        ['client_ref' => 'm-3', 'channel' => 'me', 'start_ms' => 40000, 'end_ms' => 50000, 'text' => 'Uh basically something good.'],
        ['client_ref' => 'pq-a-3', 'channel' => 'other', 'start_ms' => 51000, 'end_ms' => 55000, 'text' => 'When can you start?', 'is_question' => true],
    ]])->assertOk()->assertJsonPath('accepted', 6);
    foreach ([['sg-1', 'q-1', null, 'Say your **years** and your **stack**.'], ['sg-2', 'q-2', null, 'First draft.'], ['sg-3', 'q-2', 'sg-2', 'Give a **range**: 250,000 to 300,000.']] as [$sid, $turn, $replaces, $text]) {
        reportRelay($test, '/api/internal/assist/suggestions', ['suggestion_id' => $sid, 'session_id' => $id, 'turn_id' => $turn, 'kind' => 'answer',
            'mode' => 'fast', 'model' => 'claude-haiku-5-5', 'replaces' => $replaces, 'text' => $text, 'at' => now()->toAtomString()])->assertOk();
    }
    $api->postJson("/api/assist/sessions/{$id}/suggestions/sg-1/action", ['action' => 'used'])->assertNoContent();
    reportRelay($test, '/api/internal/assist/facts', ['session_id' => $id, 'facts' => ['Me: three years of Laravel (minute 1)'], 'at' => now()->toAtomString()])->assertOk();
    reportRelay($test, '/api/internal/assist/summary', ['session_id' => $id, 'summary' => 'An interview rehearsal.', 'at' => now()->toAtomString()])->assertOk();

    return $id;
}

describe('a Practice report', function () {
    it('pairs each q-N question with the answers after it and the hint shown, and coaches from the turns', function () {
        $ai = fakeAssistReportAi();
        $device = assistPerson('pro');
        $api = $this->api(Fx::token($device));
        $id = practiceSession($this, $this->api(Fx::token($device)));

        $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
        $report = $api->getJson("/api/assist/sessions/{$id}/report")->assertOk()->json();

        expect(array_map(fn (array $row): array => array_intersect_key($row, array_flip(['index', 'turn_id', 'question_id', 'question', 'answer', 'verdict', 'better_answer'])), $report['qa']))->toBe([
            ['index' => 1, 'turn_id' => 'q-1', 'question_id' => 'pq-a-1', 'question' => 'Tell me about yourself.', 'answer' => 'Um I am a Laravel developer, you know, for three years. I build APIs.', 'verdict' => 'good', 'better_answer' => null],
            ['index' => 2, 'turn_id' => 'q-2', 'question_id' => 'pq-a-2', 'question' => 'What salary do you expect?', 'answer' => 'Uh basically something good.', 'verdict' => 'weak', 'better_answer' => 'I am looking for 300,000 rupees a month.'],
            ['index' => 3, 'turn_id' => 'q-3', 'question_id' => 'pq-a-3', 'question' => 'When can you start?', 'answer' => null, 'verdict' => 'missed', 'better_answer' => null],
        ]);
        // The hint shown: the one that replaced the draft; and what the person did with it.
        expect($report['qa'][0]['hint'])->toBe(['suggestion_id' => 'sg-1', 'text' => 'Say your **years** and your **stack**.', 'action' => 'used'])
            ->and($report['qa'][1]['hint'])->toBe(['suggestion_id' => 'sg-3', 'text' => 'Give a **range**: 250,000 to 300,000.', 'action' => null])
            ->and($report['qa'][2]['hint'])->toBeNull()
            ->and($report['qa'][0]['answer_ms'])->toBe(24000);

        // 18 words in 34 s of speech; 4 fillers; 34 s of 46 s talked; one pause of 5 s after a question (at 35 s).
        expect($report['coaching'])->toMatchArray([
            'words' => 18, 'speaking_ms' => 34000, 'words_per_minute' => 32,
            'filler_words' => ['count' => 4, 'per_100_words' => 22.2, 'top' => [['word' => 'basically', 'count' => 1], ['word' => 'uh', 'count' => 1], ['word' => 'um', 'count' => 1], ['word' => 'you know', 'count' => 1]]],
            'talk_ratio' => ['me' => 74, 'them' => 26],
            'long_pauses' => ['count' => 1, 'longest_ms' => 5000, 'threshold_ms' => 3000, 'at' => [['at_ms' => 35000, 'ms' => 5000]]],
        ])->and($report['coaching']['grammar'])->toBe([['said' => 'I am working here since 2022', 'better' => 'I have been working here since 2022', 'why' => 'since needs the perfect']]);

        expect($report)->toMatchArray([
            'session_id' => $id, 'version' => 1, 'practice' => true, 'mode' => 'fast', 'meeting_type' => 'general',
            'ai' => ['status' => 'ready', 'reason' => null, 'model' => 'claude-sonnet-5-5', 'runs' => 1],
            'summary' => 'They asked about your notice period and salary.', 'running_summary' => 'An interview rehearsal.',
            'summary_translation' => null, // no language help asked for
            'promises' => ['Send my portfolio by Friday'], 'facts' => ['Me: three years of Laravel (minute 1)'], 'memory_added' => 0, 'used' => 1,
        ]);
        // What the model read: the questions with the answers and hints, the facts and the transcript.
        expect($ai->inputs)->toHaveCount(1)
            ->and($ai->inputs[0]['questions'][1])->toBe(['index' => 2, 'question' => 'What salary do you expect?', 'answer' => 'Uh basically something good.', 'hint' => 'Give a **range**: 250,000 to 300,000.'])
            ->and($ai->inputs[0]['transcript'][0])->toBe('[00:00] Them: Tell me about yourself.')
            ->and($ai->inputs[0]['practice'])->toBeTrue();
    });

    it('bills the report to the Practice allowance, never the live one, and writes no project memory', function () {
        fakeAssistReportAi();
        $device = assistPerson('pro');
        $id = practiceSession($this, $this->api(Fx::token($device)));
        $this->api(Fx::token($device))->postJson("/api/assist/sessions/{$id}/end")->assertOk();

        // 10,000 in × $2 + 2,000 cached × $0.10 + 1,000 written × $2.50 + 1,500 out × $10, per million: $0.0377.
        $usage = AssistUsage::query()->where('kind', 'report')->sole();
        expect($usage->assist_session_id)->toBe($id)->and((float) $usage->cost_usd)->toBe(0.0377)
            ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->where('report_id', "assist/report-{$id}-1")->count())->toBe(1);
        $plans = app(AssistPlans::class);
        expect($plans->left($device->workspace_id, $device->user_id, true)['cents_left'])->toBe(496)
            ->and($plans->left($device->workspace_id, $device->user_id, false)['cents_left'])->toBe(1500)
            ->and(ClientMemoryItem::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('a live report', function () {
    /** A live call from a prep of [$project]'s, with a recording, one question hinted by the relay's turn id. */
    function liveCall(object $test, object $api, Device $device, ?string $projectId, string $languageHelp = 'off'): string
    {
        $prep = AssistPrep::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'client_id' => $projectId,
            'meeting_type' => 'existing_client', 'title' => 'Acme check-in', 'hint_level' => 'outline', 'mode' => 'fast', 'language_help' => $languageHelp, 'status' => 'draft']);
        $recording = Fx::session($device->user, $device, ['status' => SessionStatus::Recording]);
        $id = $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'prep_id' => $prep->id, 'recording_session_id' => $recording->id])->assertCreated()->json('session.id');
        // The apps send the relay's turn id as an `other` turn's client_ref (1.9.0-j): a hint pairs with it, even
        // when the app did not mark the turn as a question.
        $api->postJson("/api/assist/sessions/{$id}/turns", ['turns' => [
            ['client_ref' => 'o-12', 'channel' => 'other', 'start_ms' => 1000, 'end_ms' => 3000, 'text' => 'Can you ship by Friday?'],
            ['client_ref' => 'me-local-1', 'channel' => 'me', 'start_ms' => 3500, 'end_ms' => 6000, 'text' => 'Yes, Friday works.'],
            ['client_ref' => 'o-13', 'channel' => 'other', 'start_ms' => 7000, 'end_ms' => 8000, 'text' => 'Great.'],
        ]])->assertOk();
        reportRelay($test, '/api/internal/assist/suggestions', ['suggestion_id' => 'live-1', 'session_id' => $id, 'turn_id' => 'o-12', 'kind' => 'answer',
            'mode' => 'fast', 'model' => 'claude-haiku-5-5', 'text' => 'Say **yes**, by Friday.', 'at' => now()->toAtomString()])->assertOk();

        return $id;
    }

    it('pairs a hint with its question by the relay\'s turn id, translates the summary, and adds the action items to the project memory', function () {
        $ai = fakeAssistReportAi();
        $device = assistPerson('pro');
        $project = Fx::client($device->user, 'Acme');
        $id = liveCall($this, $this->api(Fx::token($device)), $device, $project->id, 'roman_urdu');
        $api = $this->api(Fx::token($device));

        $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
        $report = $api->getJson("/api/assist/sessions/{$id}/report")->assertOk()->json();

        expect($report['qa'])->toHaveCount(1)
            ->and($report['qa'][0])->toMatchArray(['turn_id' => 'o-12', 'question' => 'Can you ship by Friday?', 'answer' => 'Yes, Friday works.'])
            ->and($report['qa'][0]['hint']['text'])->toBe('Say **yes**, by Friday.')
            ->and($report['summary_translation'])->toBe(['language' => 'roman_urdu', 'text' => 'Unhon ne notice period aur salary ka poocha.'])
            ->and($report['follow_up_email'])->toBe(['subject' => 'Thank you', 'body' => 'Thank you for your time today.'])
            ->and($report['memory_added'])->toBe(2)
            ->and($ai->inputs[0]['translate_to'])->toBe('roman_urdu');
        $session = AssistSession::query()->withoutGlobalScopes()->find($id);
        $lines = ClientMemoryItem::query()->withoutGlobalScopes()->where('client_id', $project->id)->orderBy('text')->get();
        expect($lines->pluck('text')->all())->toBe(['My action: Ask about the start date', 'My action: Send the portfolio by Friday'])
            ->and($lines->first()->toApi()['source'])->toBe(['type' => 'call', 'id' => $session->capture_session_id, 'at_ms' => null]);

        // Made again (a late post): its lines are replaced, not added twice; a pinned one stays.
        $lines->first()->forceFill(['pinned' => true])->save();
        reportRelay($this, '/api/internal/assist/summary', ['session_id' => $id, 'summary' => 'Later.', 'at' => now()->addMinute()->toAtomString()])->assertOk();
        expect(ClientMemoryItem::query()->withoutGlobalScopes()->where('client_id', $project->id)->count())->toBe(3);

        // Deleting the session takes its lines (not the pinned one).
        $api->deleteJson("/api/assist/sessions/{$id}")->assertNoContent();
        expect(ClientMemoryItem::query()->withoutGlobalScopes()->where('client_id', $project->id)->pluck('text')->all())->toBe(['My action: Ask about the start date']);
    });

    it('adds nothing to a project whose AI notes are off, nor to another workspace\'s project', function () {
        fakeAssistReportAi();
        $device = assistPerson('pro');
        $off = Fx::client($device->user, 'Quiet');
        $off->forceFill(['ai_notes' => false])->save();
        $id = liveCall($this, $this->api(Fx::token($device)), $device, $off->id);
        $this->api(Fx::token($device))->postJson("/api/assist/sessions/{$id}/end")->assertOk();

        // Another person's project, even one in the same workspace (projects are private).
        $theirs = Fx::client(Fx::user(), 'Someone else');
        $theirs->forceFill(['workspace_id' => $device->workspace_id])->save();
        AssistSession::query()->withoutGlobalScopes()->find($id)->prep->forceFill(['client_id' => $theirs->id])->save();
        (new MakeAssistReportJob($id))->handle();

        expect(ClientMemoryItem::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('without the model', function () {
    it('still gives the table, the coaching, the running summary and the facts, and says why the rest is missing', function (string $why) {
        $device = assistPerson('pro');
        if ($why === 'unavailable') {
            fakeAssistReportAi(fails: new RuntimeException(ClaudeAssistReportAi::UNAVAILABLE));
        } elseif ($why === 'budget') {
            fakeAssistReportAi();
        }
        $id = practiceSession($this, $this->api(Fx::token($device)));
        $api = $this->api(Fx::token($device));
        if ($why === 'budget') {
            // The Practice allowance spent during the session.
            AssistUsage::query()->create(['assist_session_id' => $id, 'workspace_id' => $device->workspace_id, 'user_id' => $device->user_id,
                'suggestion_id' => 'spent', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'fast', 'kind' => 'answer', 'cost_usd' => 5.0, 'occurred_at' => now()]);
        }
        $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
        $report = $api->getJson("/api/assist/sessions/{$id}/report")->assertOk()->json();

        expect($report['ai'])->toMatchArray(['status' => $why === 'budget' ? 'skipped' : 'failed', 'reason' => $why])
            ->and($report['qa'])->toHaveCount(3)->and($report['qa'][2]['verdict'])->toBe('missed')->and($report['qa'][0]['verdict'])->toBeNull()
            ->and($report['summary'])->toBe('An interview rehearsal.')->and($report['coaching']['words'])->toBe(18)
            ->and($report['facts'])->toBe(['Me: three years of Laravel (minute 1)'])
            ->and($report['follow_up_email'])->toBeNull()
            ->and(AssistUsage::query()->where('kind', 'report')->count())->toBe(0);
    })->with(['not_set_up', 'budget', 'unavailable']);

    it('asks the model nothing when the person said nothing', function () {
        $ai = fakeAssistReportAi();
        $device = assistPerson('pro');
        $api = $this->api(Fx::token($device));
        $id = $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->json('session.id');
        $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();

        expect($api->getJson("/api/assist/sessions/{$id}/report")->assertOk()->json('ai'))->toMatchArray(['status' => 'skipped', 'reason' => 'nothing_said'])
            ->and($ai->inputs)->toBe([]);
    });
});

it('asks the model at most three times however often the report is made again, then keeps its last answer', function () {
    $ai = fakeAssistReportAi();
    $device = assistPerson('pro');
    $id = practiceSession($this, $this->api(Fx::token($device)));
    $api = $this->api(Fx::token($device));
    $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
    foreach (range(1, 4) as $minute) {
        reportRelay($this, '/api/internal/assist/summary', ['session_id' => $id, 'summary' => "Late {$minute}.", 'at' => now()->addMinutes($minute)->toAtomString()])->assertOk();
    }

    $report = $api->getJson("/api/assist/sessions/{$id}/report")->assertOk()->json();
    expect($ai->inputs)->toHaveCount(3)->and($report['ai']['runs'])->toBe(3)->and($report['running_summary'])->toBe('Late 4.')
        ->and($report['qa'][1]['verdict'])->toBe('weak')->and($report['summary'])->toBe('They asked about your notice period and salary.')
        ->and(AssistUsage::query()->where('kind', 'report')->count())->toBe(3);
});

it('keeps to its JSON shape, which the apps read', function () {
    fakeAssistReportAi();
    $device = assistPerson('pro');
    $id = practiceSession($this, $this->api(Fx::token($device)));
    $api = $this->api(Fx::token($device));
    $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
    $report = $api->getJson("/api/assist/sessions/{$id}/report")->assertOk()->json();

    expect(array_keys($report))->toBe(['session_id', 'version', 'mode', 'practice', 'meeting_type', 'title', 'started_at', 'ended_at', 'seconds', 'turns', 'ai',
        'summary', 'summary_translation', 'qa', 'mistakes', 'promises', 'coaching', 'action_items', 'memory_added', 'follow_up_email', 'facts', 'running_summary', 'suggestions', 'used'])
        ->and(array_keys($report['ai']))->toBe(['status', 'reason', 'model', 'runs'])
        ->and(array_keys($report['qa'][0]))->toBe(['index', 'turn_id', 'question_id', 'at_ms', 'question', 'answer', 'answer_ms', 'hint', 'verdict', 'better_answer'])
        ->and(array_keys($report['coaching']))->toBe(['words', 'speaking_ms', 'words_per_minute', 'filler_words', 'talk_ratio', 'long_pauses', 'grammar'])
        ->and(array_keys($report['mistakes'][0]))->toBe(['said', 'fact'])
        ->and(array_keys($report['coaching']['grammar'][0]))->toBe(['said', 'better', 'why'])
        ->and(array_keys($report['suggestions'][0]))->toBe(['id', 'turn_id', 'kind', 'mode', 'text', 'action']);
    foreach (['summary', 'running_summary', 'started_at', 'ended_at'] as $text) {
        expect($report[$text])->toBeString();
    }
    foreach (['promises', 'action_items', 'facts'] as $list) {
        expect($report[$list])->toBeArray()->each->toBeString();
    }
});

it('counts the coaching numbers on fixtures', function () {
    $turn = fn (string $channel, int $start, int $end, string $text) => tap(new AssistTurn, fn ($t) => $t->forceFill(['channel' => $channel, 'start_ms' => $start, 'end_ms' => $end, 'text' => $text]));
    $none = AssistReport::coaching(new Collection);
    expect($none)->toMatchArray(['words' => 0, 'words_per_minute' => null, 'talk_ratio' => null])->and($none['long_pauses']['count'])->toBe(0);

    // Short speech has no pace; "I mean" and "matlab" count as phrases, not inside other words ("umbrella").
    $short = AssistReport::coaching(new Collection([$turn('me', 0, 5000, 'I mean the umbrella, matlab, yaani okay')]));
    expect($short['words_per_minute'])->toBeNull()->and($short['filler_words']['count'])->toBe(3);

    // A pause counts only after the other side finished, and only from the threshold.
    $pauses = AssistReport::coaching(new Collection([
        $turn('other', 0, 1000, 'Q1'), $turn('me', 3999, 5000, 'a'), $turn('other', 6000, 7000, 'Q2'), $turn('me', 10000, 70000, str_repeat('word ', 120)),
    ]));
    expect($pauses['long_pauses'])->toMatchArray(['count' => 1, 'longest_ms' => 3000])->and($pauses['words_per_minute'])->toBe(119)
        ->and($pauses['talk_ratio'])->toBe(['me' => 97, 'them' => 3]);
});
