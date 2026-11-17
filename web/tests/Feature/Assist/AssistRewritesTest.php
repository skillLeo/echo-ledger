<?php

declare(strict_types=1);

use App\Models\AssistSuggestion;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Services\Assist\AssistTransport;
use App\Services\SettingsRepository;
use Tests\Feature\Security\Support\ApiClient;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * What Me asks for in HTTP mode (1.9.0-q): help now, the rewrites of a finished suggestion (regenerate, shorter, formal,
 * translate), cancel, and Practice's `practice_next` with its questions, hints and `practice_done`.
 */

beforeEach(function () {
    assistRelaySetUp();
    app(SettingsRepository::class)->set('assist.connection', AssistTransport::SHARED);
    config(['assist.http.hold_max_ms' => 300, 'assist.http.hold_poll_ms' => 10, 'assist.http.card_check_ms' => 0]);
});

const ASSIST_NOTICE = ['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'other', 'text' => 'What is your notice period?', 'start_ms' => 1000, 'end_ms' => 4000];

/** A session in which the question o-1 was answered with [$answer] (the Fast model), and its suggestion id. */
function assistAnswered(object $test, string $answer = 'One month. Flexible.', string $plan = 'pro'): array
{
    $models = fakeAssistModels()->on('claude-haiku-5-5', [$answer, ['usage' => [300, 0, 0, 10]]]);
    [$api, $id] = httpSession($test, $plan);
    $first = assistLive($api, $id, ASSIST_NOTICE)[0]['data']['suggestion_id'];

    return [$api, $id, $first, $models];
}

it('gives help now for the latest words of the other side, or for the turn asked, or an opening before anything was said', function () {
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);

    // Before anything was said: how to open the call.
    $events = assistLive($api, $id, ['type' => 'help_now']);
    expect(assistNames($events))->toContain('suggestion_start')->and($events[0]['data'])->toMatchArray(['kind' => 'help_now', 'turn_id' => null, 'replaces' => null, 'speculative' => false])
        ->and($models->requests[0]->prompt->text())->toContain('before Them said anything');

    assistLive($api, $id, [...ASSIST_NOTICE, 'text' => 'Okay.', 'turn_id' => 'o-1']);
    assistLive($api, $id, [...ASSIST_NOTICE, 'turn_id' => 'o-2', 'text' => 'And what salary do you expect?']);
    assistLive($api, $id, [...ASSIST_NOTICE, 'turn_id' => 'o-3', 'text' => 'Can you start in January?']);
    $latest = assistLive($api, $id, ['type' => 'help_now']);
    $asked = assistLive($api, $id, ['type' => 'help_now', 'turn_id' => 'o-2']);

    expect($latest[0]['data']['turn_id'])->toBe('o-3')->and($asked[0]['data']['turn_id'])->toBe('o-2');
    $prompts = array_map(fn ($r) => $r->prompt->text(), $models->requests);
    expect(end($prompts))->toContain('And what salary do you expect?')->toContain('(Me asked for help now: suggest what Me can say next.)');
    expect(AssistUsage::query()->where('assist_session_id', $id)->where('kind', 'help_now')->count())->toBe(3);
});

it('gives help now while suggestions are paused, which stops only the automatic ones', function () {
    fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistLive($api, $id, ['type' => 'context_update', 'settings' => ['assist' => 'paused']]);

    expect(assistNames(assistLive($api, $id, ASSIST_NOTICE)))->toBe(['end'])
        ->and(assistNames(assistLive($api, $id, ['type' => 'help_now'])))->toContain('suggestion_start');
});

it('rewrites the latest finished suggestion in the way asked, as a new suggestion that replaces it', function (string $type, string $needle) {
    [$api, $id, $first, $models] = assistAnswered($this);
    $models->on('claude-haiku-5-5', ['Shorter.', ['usage' => [320, 0, 0, 5]]]);

    $events = assistLive($api, $id, ['type' => $type, ...($type === 'translate' ? ['language' => 'roman_urdu'] : [])]);

    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end'])
        ->and($events[0]['data'])->toMatchArray(['kind' => $type, 'turn_id' => 'o-1', 'replaces' => $first, 'draft' => false, 'speculative' => false, 'mode' => 'balanced', 'model' => 'claude-haiku-5-5'])
        ->and($events[2]['data']['status'])->toBe('done');
    $prompt = $models->requests[1]->prompt->text();
    // The words it rewrites, the earlier suggestion, and what was asked.
    expect($prompt)->toContain("What is your notice period?\n\nThe previous suggestion:\nOne month. Flexible.")->toContain($needle)->not->toContain('Hint level:');
    expect(AssistUsage::query()->where('assist_session_id', $id)->where('kind', $type)->sole()->outcome)->toBe('done');
    $kept = AssistSuggestion::query()->where('suggestion_id', $events[0]['data']['suggestion_id'])->sole();
    expect($kept->kind)->toBe($type)->and($kept->replaces)->toBe($first)->and($kept->text)->toBe('Shorter.');
})->with([
    'regenerate' => ['regenerate', 'Do not repeat the previous one.'],
    'shorter' => ['shorter', 'at most 12 words'],
    'formal' => ['formal', 'more formal, polite tone'],
    'translate' => ['translate', 'Language: Roman Urdu, then English'],
]);

it('rewrites the suggestion it is asked for by id, and the latest when it is not', function () {
    [$api, $id, $first, $models] = assistAnswered($this);
    $models->on('claude-haiku-5-5', ['Second answer.', ['usage' => [300, 0, 0, 10]]]);
    $second = assistLive($api, $id, [...ASSIST_NOTICE, 'turn_id' => 'o-2', 'text' => 'And the start date?'])[0]['data']['suggestion_id'];

    $byId = assistLive($api, $id, ['type' => 'formal', 'suggestion_id' => $first]);
    $latest = assistLive($api, $id, ['type' => 'shorter']);

    expect($byId[0]['data'])->toMatchArray(['turn_id' => 'o-1', 'replaces' => $first]);
    // The latest finished is the formal rewrite just made, and it keeps the turn of the question it answers.
    expect($latest[0]['data']['replaces'])->toBe($byId[0]['data']['suggestion_id'])->and($latest[0]['data']['turn_id'])->toBe('o-1')->and($second)->not->toBe($first);
});

it('never rewrites a hard question\'s draft into an upgrade: a rewrite goes to one model', function () {
    $models = fakeAssistModels()->on('claude-haiku-5-5', ['Draft.', ['usage' => [300, 0, 0, 10]]])->on('claude-sonnet-5-5', ['Upgrade.', ['usage' => [900, 0, 0, 5]]]);
    [$api, $id] = httpSession($this);
    assistLive($api, $id, ['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'other', 'text' => 'Why did you leave your last job, and what would you do differently now?', 'start_ms' => 1, 'end_ms' => 2]);

    $events = assistLive($api, $id, ['type' => 'regenerate']);

    // The latest finished suggestion is the upgrade, which replaced the draft.
    expect($events[0]['data'])->toMatchArray(['kind' => 'regenerate', 'draft' => false])
        ->and($models->asked())->toBe(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5']);
    expect($models->requests[2]->prompt->text())->toContain("The previous suggestion:\nUpgrade.");
});

it('says there is nothing to rewrite before a suggestion finished, and a skipped or failed one is none', function () {
    $models = fakeAssistModels()->on('claude-haiku-5-5', ['SKIP', ['usage' => [300, 0, 0, 2]]]);
    [$api, $id] = httpSession($this);

    $none = assistLive($api, $id, ['type' => 'shorter']);
    expect(assistNames($none))->toBe(['error', 'end'])
        ->and($none[0]['data'])->toMatchArray(['code' => 'nothing_to_rewrite', 'retry' => false, 'message' => 'There is no finished suggestion to change yet.', 'suggestion_id' => null, 'in_reply_to' => null, 'fatal' => false]);

    assistLive($api, $id, ASSIST_NOTICE);
    expect(assistNames(assistLive($api, $id, ['type' => 'regenerate'])))->toBe(['error', 'end'])
        ->and(assistLive($api, $id, ['type' => 'formal', 'suggestion_id' => 'no-such-one'])[0]['data']['code'])->toBe('nothing_to_rewrite')
        ->and($models->asked())->toBe(['claude-haiku-5-5']);
});

it('translates only into a language that has words, and does nothing for off', function () {
    [$api, $id, , $models] = assistAnswered($this);

    expect(assistNames(assistLive($api, $id, ['type' => 'translate', 'language' => 'off'])))->toBe(['end'])
        ->and(assistLive($api, $id, ['type' => 'translate', 'language' => 'klingon'])[0]['data']['code'])->toBe('bad_message')
        ->and(assistLive($api, $id, ['type' => 'translate'])[0]['data']['code'])->toBe('bad_message')
        ->and($models->asked())->toBe(['claude-haiku-5-5']);
    $arabic = assistLive($api, $id, ['type' => 'translate', 'language' => 'arabic']);
    expect($arabic[0]['data']['kind'])->toBe('translate')->and($models->requests[1]->prompt->text())->toContain('Language: Arabic, then English');
});

it('tells the person who asked that no budget is free right now, and that suggestions are off at the end of it', function () {
    [$api, $id] = httpSession($this);
    fakeAssistModels();
    assistLive($api, $id, ASSIST_NOTICE);
    $blocker = assistHoldAllBudget($id);

    $events = assistLive($api, $id, ['type' => 'regenerate']);
    expect($events[0]['data'])->toMatchArray(['code' => 'budget_exhausted', 'retry' => true]);

    $blocker->delete();
    assistSpend($id, 15);
    $off = assistLive($api, $id, ['type' => 'regenerate']);
    expect(assistNames($off))->toBe(['mode_changed', 'error', 'end'])->and($off[1]['data'])->toMatchArray(['code' => 'budget_exhausted', 'retry' => false]);
});

// ---------------------------------------------------------------------------------------------------------------------
// Practice
// ---------------------------------------------------------------------------------------------------------------------

/** A Practice session on the Free plan (Practice is on every plan), and its API client. */
function assistPractice(object $test): array
{
    return httpSession($test, 'free', ['practice' => true, 'mode' => 'fast']);
}

it('answers practice_next with a question, and in the same stream the hint for it', function () {
    $models = fakeAssistModels()->on('claude-haiku-5-5', ['"Tell me about a project you are proud of?"', ['usage' => [400, 0, 0, 15]]]);
    [$api, $id] = assistPractice($this);
    assistLive($api, $id, ['type' => 'context_update', 'brief' => ['meeting_type' => 'interview', 'title' => 'Backend role']]);

    $events = assistLive($api, $id, ['type' => 'practice_next']);

    expect(assistNames($events))->toBe(['practice_question', 'suggestion_start', 'token', 'token', 'suggestion_end', 'end'])
        ->and($events[0]['data'])->toMatchArray(['turn_id' => 'q-1', 'index' => 1, 'total' => 8, 'text' => 'Tell me about a project you are proud of?'])
        ->and($events[0]['data']['question_id'])->toStartWith('pq-')
        ->and($events[1]['data'])->toMatchArray(['kind' => 'answer', 'turn_id' => 'q-1', 'mode' => 'fast']);
    // The question is Them's turn, stored by the server for the report: the hint pairs with it by q-N.
    $turn = AssistTurn::query()->where('assist_session_id', $id)->sole();
    expect($turn)->toMatchArray(['client_ref' => $events[0]['data']['question_id'], 'channel' => 'other', 'is_question' => true, 'text' => 'Tell me about a project you are proud of?'])
        ->and(AssistSuggestion::query()->sole()->turn_id)->toBe('q-1');
    // The question is written from the brief, as the interviewer, on the Fast model; both calls are billed to Practice.
    expect($models->requests[0]->prompt->text())->toContain('You play Them: the interviewer.')->toContain('Title: Backend role')->toContain('Ask question 1 of 8.')
        ->and($models->requests[0]->maxOutputTokens)->toBe(120);
    expect(AssistUsage::query()->where('assist_session_id', $id)->orderBy('id')->pluck('kind')->all())->toBe(['practice_question', 'answer']);
});

it('asks the next question knowing the ones already asked and what Me said, and ends with practice_done after the last', function () {
    $models = fakeAssistModels();
    [$api, $id] = assistPractice($this);
    $questions = array_map(fn (int $i): string => "Question number {$i}?", range(1, 8));
    foreach ($questions as $i => $question) {
        $models->on('claude-haiku-5-5', [$question, ['usage' => [400, 0, 0, 15]]]);
        // The hint for it, then Me's answer.
        $models->on('claude-haiku-5-5', ['A hint.', ['usage' => [400, 0, 0, 15]]]);
        $events = assistLive($api, $id, ['type' => 'practice_next']);
        expect($events[0]['data'])->toMatchArray(['index' => $i + 1, 'turn_id' => 'q-'.($i + 1), 'text' => $question]);
        assistLive($api, $id, ['type' => 'turn_final', 'turn_id' => 'm-'.$i, 'channel' => 'me', 'text' => 'My answer '.($i + 1).'.', 'start_ms' => 10_000 * ($i + 1)]);
        // Ten messages a second at most: Me takes a moment to answer.
        $this->travel(2)->seconds();
    }

    // The second question's prompt lists the first and Me's answer to it.
    $second = $models->requests[2]->prompt->text();
    expect($second)->toContain("## Questions already asked\n- Question number 1?")->toContain('Me: My answer 1.')->toContain('Ask question 2 of 8.');

    $this->travel(2)->seconds();
    $done = assistLive($api, $id, ['type' => 'practice_next']);
    expect(assistNames($done))->toBe(['practice_done', 'end'])->and($done[0]['data']['asked'])->toBe(8)->and(assistLive($api, $id, ['type' => 'practice_next'])[0]['data']['asked'])->toBe(8);
});

it('gives no hint for a practice question while suggestions are paused', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['What is your notice period?', ['usage' => [400, 0, 0, 15]]]);
    [$api, $id] = assistPractice($this);
    assistLive($api, $id, ['type' => 'context_update', 'settings' => ['assist' => 'paused']]);

    expect(assistNames(assistLive($api, $id, ['type' => 'practice_next'])))->toBe(['practice_question', 'end']);
});

it('refuses practice_next outside Practice, and while the last question is still being written', function () {
    // One scripted gateway for the whole test: the router keeps the controller of its first request.
    $models = fakeAssistModels();
    [$live, $liveId] = httpSession($this);
    $refused = assistLive($live, $liveId, ['type' => 'practice_next']);
    expect($refused[0]['data'])->toMatchArray(['code' => 'not_practice', 'retry' => false, 'message' => 'This is not a practice session.']);

    $inner = [];
    $models->on('claude-haiku-5-5', [['do' => function () use (&$inner, &$api, &$id) {
        $inner = assistLive($api, $id, ['type' => 'practice_next']);
    }], 'A question?', ['usage' => [400, 0, 0, 15]]]);
    [$api, $id] = assistPractice($this);

    $outer = assistLive($api, $id, ['type' => 'practice_next']);

    expect(assistNames($inner))->toBe(['error', 'end'])->and($inner[0]['data'])->toMatchArray(['code' => 'practice_busy', 'retry' => true])
        ->and(assistNames($outer))->toContain('practice_question');
    // Asked again afterwards, it is not busy.
    expect(assistLive($api, $id, ['type' => 'practice_next'])[0]['event'])->toBe('practice_question');
});

it('tells the app a question could not be written, twice with retry and the third time without, and then there is no more practice', function () {
    $models = fakeAssistModels();
    [$api, $id] = assistPractice($this);
    foreach (range(1, 3) as $try) {
        $models->on('claude-haiku-5-5', [['fail' => 'timeout']])->on('openai/gpt-oss-120b', [['fail' => 'unavailable']]);
        $error = assistLive($api, $id, ['type' => 'practice_next'])[0]['data'];
        expect($error)->toMatchArray(['code' => 'provider_unavailable', 'retry' => $try < 3, 'suggestion_id' => null]);
    }

    $done = assistLive($api, $id, ['type' => 'practice_next']);
    expect($done[0]['event'])->toBe('practice_done')->and($done[0]['data']['asked'])->toBe(0);
    // Each failed try was billed what it took.
    expect(AssistUsage::query()->where('assist_session_id', $id)->where('kind', 'practice_question')->count())->toBe(3);
});

it('counts a question the model could not make into a usable line as a failed try', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ["\n  \n", ['usage' => [400, 0, 0, 1]]]);
    [$api, $id] = assistPractice($this);

    $error = assistLive($api, $id, ['type' => 'practice_next'])[0]['data'];

    expect($error)->toMatchArray(['code' => 'provider_unavailable', 'retry' => true])
        ->and(AssistTurn::query()->where('assist_session_id', $id)->count())->toBe(0);
});

it('does not start a practice question when the allowance is used up', function () {
    $models = fakeAssistModels();
    [$api, $id] = assistPractice($this);
    assistSpend($id, 1);

    $events = assistLive($api, $id, ['type' => 'practice_next']);

    expect(assistNames($events))->toBe(['mode_changed', 'error', 'end'])->and($events[1]['data'])->toMatchArray(['code' => 'budget_exhausted', 'retry' => false, 'message' => 'Practice is off: this session\'s allowance is used up.'])
        ->and($models->requests)->toBe([]);
});
