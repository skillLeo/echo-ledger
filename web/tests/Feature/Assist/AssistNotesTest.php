<?php

declare(strict_types=1);

use App\Jobs\UpdateLiveFactsJob;
use App\Jobs\UpdateLiveSummaryJob;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Models\UsageLedgerEntry;
use App\Services\Assist\AssistBudget;
use App\Services\Assist\AssistRecords;
use App\Services\Assist\AssistTransport;
use App\Services\Assist\Http\CallNotes;
use App\Services\Assist\Http\LiveContext;
use App\Services\SettingsRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Security\Support\ApiClient;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * The facts ledger and the running summary in HTTP mode (1.9.0-q): written in the background on the queue, on the Fast
 * model within the budget, never holding up a suggestion.
 */

beforeEach(function () {
    assistRelaySetUp();
    app(SettingsRepository::class)->set('assist.connection', AssistTransport::SHARED);
    config(['assist.http.card_check_ms' => 0]);
});

/** A final line of the call: [$who] `other` or `me`; each is a second after the one before it, from 00:01. */
function assistLine(string $id, string $text, string $who = 'other'): array
{
    $n = AssistTurn::query()->where('assist_session_id', $id)->count() + 1;

    return ['type' => 'turn_final', 'turn_id' => "l-{$n}", 'channel' => $who, 'text' => $text, 'start_ms' => $n * 1000, 'end_ms' => $n * 1000 + 500];
}

/** Sends [$lines] (each `[who, text]`) without asking for suggestions: paused, so only the notes follow. */
function assistSay(object $api, string $id, array $lines): void
{
    assistLive($api, $id, ['type' => 'context_update', 'settings' => ['assist' => 'paused']]);
    foreach ($lines as [$who, $text]) {
        assistLive($api, $id, assistLine($id, $text, $who));
    }
}

it('starts the facts call after every third final line of either side, and not for an "okay"', function () {
    Queue::fake();
    fakeAssistModels();
    [$api, $id] = httpSession($this);

    assistSay($api, $id, [['other', 'We can pay 8,000 USD.'], ['me', 'Okay.'], ['me', 'That works for me.']]);
    Queue::assertNothingPushed();

    assistSay($api, $id, [['other', 'Delivery is on Thursday.']]);
    Queue::assertPushed(UpdateLiveFactsJob::class, 1);

    // One at a time: while that call is out, more lines start no second one.
    assistSay($api, $id, [['other', 'And the second phase in March.'], ['me', 'Noted.'], ['other', 'Any questions?']]);
    Queue::assertPushed(UpdateLiveFactsJob::class, 1);
});

it('adds the facts to the ledger the suggestions read, stores it for the report, and bills the call as facts', function () {
    Queue::fake();
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'We can pay 8,000 USD.'], ['me', 'That works for me.'], ['other', 'Delivery is on Thursday.']]);
    $models->on('claude-haiku-5-5', ["- Them: the budget is 8,000 USD (minute 0)\n[00:03] Them: delivery is on Thursday\nMe: agreed\nsomething else\n", ['usage' => [500, 0, 0, 30]]]);

    app(CallNotes::class)->facts($id);

    $session = assistSessionOf($id);
    $facts = ['Them: the budget is 8,000 USD (minute 0)', 'Them: delivery is on Thursday', 'Me: agreed'];
    expect(app(LiveContext::class)->get($session)['facts'])->toBe($facts)->and($session->facts)->toBe($facts)->and($session->facts_at)->not->toBeNull();
    // The model read the rules, the brief, the ledger so far (none) and the new lines with their times and sides.
    $prompt = $models->requests[0]->prompt->text();
    expect($models->requests[0]->model)->toBe('claude-haiku-5-5')->and($prompt)->toContain('# Live Assist: facts ledger')->toContain("## The ledger so far\n(none)")
        ->and($prompt)->toContain("## New lines of the call\n[00:01] Them: We can pay 8,000 USD.\n[00:02] Me: That works for me.\n[00:03] Them: Delivery is on Thursday.");
    $usage = AssistUsage::query()->where('assist_session_id', $id)->sole();
    expect($usage->kind)->toBe('facts')->and($usage->outcome)->toBe('done')->and($usage->mode)->toBe('fast')->and(str_starts_with($usage->suggestion_id, 'facts-'))->toBeTrue()
        ->and(UsageLedgerEntry::query()->where('use_case', 'live_assist')->where('report_id', 'assist/'.$usage->suggestion_id)->count())->toBe(1);

    // The next suggestion reads the ledger.
    assistLive($api, $id, ['type' => 'context_update', 'settings' => ['assist' => 'on']]);
    assistLive($api, $id, ['type' => 'help_now']);
    expect(end($models->requests)->prompt->text())->toContain("- Them: the budget is 8,000 USD (minute 0)\n- Them: delivery is on Thursday\n- Me: agreed");
});

it('leaves a fact out that the ledger holds already, ignoring case and spacing, and keeps the newest 200', function () {
    Queue::fake();
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    $session = assistSessionOf($id);
    app(LiveContext::class)->addFacts($session, ['Them: the budget is 8,000 USD']);
    assistSay($api, $id, [['other', 'Budget again.'], ['other', 'And again.'], ['other', 'Once more.']]);
    $models->on('claude-haiku-5-5', ["Them:  The Budget is 8,000 USD \nThem: a new one\nNONE\n", ['usage' => [500, 0, 0, 30]]]);

    app(CallNotes::class)->facts($id);

    expect(app(LiveContext::class)->get($session)['facts'])->toBe(['Them: the budget is 8,000 USD', 'Them: a new one']);

    $many = array_map(fn (int $i): string => "Them: fact number {$i}", range(1, 250));
    app(LiveContext::class)->addFacts($session, $many);
    $ledger = app(LiveContext::class)->get($session)['facts'];
    expect($ledger)->toHaveCount(200)->and($ledger[0])->toBe('Them: fact number 51')->and(end($ledger))->toBe('Them: fact number 250');
});

it('adds nothing when the model says NONE, and asks again only for lines it has not seen', function () {
    Queue::fake();
    $models = fakeAssistModels()->on('claude-haiku-5-5', ["NONE\n", ['usage' => [500, 0, 0, 2]]])->on('claude-haiku-5-5', ["Them: the offer is 90,000\n", ['usage' => [500, 0, 0, 12]]]);
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'Hello.'], ['other', 'How are you?'], ['me', 'Fine, thanks.']]);

    app(CallNotes::class)->facts($id);
    expect(app(LiveContext::class)->get(assistSessionOf($id))['facts'])->toBe([]);

    assistSay($api, $id, [['other', 'We offer 90,000.'], ['me', 'Interesting.'], ['other', 'Think it over.']]);
    app(CallNotes::class)->facts($id);

    $second = $models->requests[1]->prompt->text();
    expect($second)->toContain('We offer 90,000.')->not->toContain('How are you?')
        ->and(app(LiveContext::class)->get(assistSessionOf($id))['facts'])->toBe(['Them: the offer is 90,000']);
});

it('waits, its lines kept, when no room is free beside the suggestions, and then reads them all', function () {
    Queue::fake();
    $models = fakeAssistModels()->on('claude-haiku-5-5', ["Them: the budget is 8,000 USD\n", ['usage' => [500, 0, 0, 12]]]);
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'The budget is 8,000 USD.'], ['me', 'Good.'], ['other', 'Next, delivery.']]);
    // One cent is free: the call's worst case needs a cent and the suggestions' reserve another.
    assistSpend($id, 14.99);

    app(CallNotes::class)->facts($id);
    expect($models->requests)->toBe([])->and(AssistUsage::query()->where('kind', 'facts')->count())->toBe(0);

    AssistUsage::query()->where('suggestion_id', 'like', 'spent-%')->delete();
    assistSay($api, $id, [['other', 'The start date is May.']]);
    app(CallNotes::class)->facts($id);

    expect($models->requests)->toHaveCount(1)
        ->and($models->requests[0]->prompt->text())->toContain('The budget is 8,000 USD.')->toContain('Next, delivery.')->toContain('The start date is May.');
});

it('asks nothing in captions-only mode, and loses the lines of a call that failed after it started', function () {
    Queue::fake();
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'The budget is 8,000 USD.'], ['me', 'Good.'], ['other', 'Next, delivery.']]);
    assistSpend($id, 15);
    app(CallNotes::class)->facts($id);
    expect($models->requests)->toBe([]);

    AssistUsage::query()->where('suggestion_id', 'like', 'spent-%')->delete();
    $models->on('claude-haiku-5-5', [['fail' => 'timeout']])->on('openai/gpt-oss-120b', [['fail' => 'unavailable']]);
    app(CallNotes::class)->facts($id);
    // Both models failed: what was taken is billed as an estimate, nothing reaches the ledger, the busy flag is gone.
    $usage = AssistUsage::query()->where('kind', 'facts')->sole();
    expect($usage->outcome)->toBe('error')->and($usage->estimated)->toBeTrue()->and(app(LiveContext::class)->get(assistSessionOf($id))['facts'])->toBe([]);

    // Its lines are not asked again: the next call reads only what came after.
    $models->on('claude-haiku-5-5', ["Them: a later fact\n", ['usage' => [500, 0, 0, 12]]]);
    assistSay($api, $id, [['other', 'Something later.']]);
    app(CallNotes::class)->facts($id);
    expect(end($models->requests)->prompt->text())->toContain('Something later.')->not->toContain('The budget is 8,000 USD.');
});

it('does nothing for a session that has ended, and says it is free to start again', function () {
    Queue::fake();
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'The budget is 8,000 USD.'], ['me', 'Good.'], ['other', 'Next, delivery.']]);
    $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();

    app(CallNotes::class)->facts($id);
    app(CallNotes::class)->summary($id);

    expect($models->requests)->toBe([]);
});

it('writes the running summary at most every five minutes, when the call moved on', function () {
    Queue::fake();
    fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'We talked about salary.']]);
    Queue::assertNotPushed(UpdateLiveSummaryJob::class);

    $this->travel(6)->minutes();
    assistSay($api, $id, [['me', 'I would like 300,000.']]);
    Queue::assertPushed(UpdateLiveSummaryJob::class, 1);
    // One at a time.
    assistSay($api, $id, [['other', 'That is possible.']]);
    Queue::assertPushed(UpdateLiveSummaryJob::class, 1);
});

it('rewrites the summary from the last one and the new lines, and the next suggestion reads it', function () {
    Queue::fake();
    $models = fakeAssistModels()->on('claude-haiku-5-5', ["  Salary talk: they offer 90,000, Me wants 300,000.  \n", ['usage' => [600, 0, 0, 20]]])
        ->on('claude-haiku-5-5', ['Salary talk, and a start date in May.', ['usage' => [600, 0, 0, 20]]]);
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'We offer 90,000.'], ['me', 'I would like 300,000.']]);

    app(CallNotes::class)->summary($id);

    $session = assistSessionOf($id);
    expect(app(LiveContext::class)->get($session)['summary'])->toBe('Salary talk: they offer 90,000, Me wants 300,000.')->and($session->summary)->toBe('Salary talk: they offer 90,000, Me wants 300,000.')->and($session->summary_at)->not->toBeNull();
    $first = $models->requests[0]->prompt->text();
    expect($first)->toContain('# Live Assist: running summary')->toContain("## The previous summary\n(none yet)")->toContain('[00:01] Them: We offer 90,000.');
    expect(AssistUsage::query()->where('kind', 'summary')->sole())->toMatchArray(['outcome' => 'done', 'mode' => 'fast']);

    assistSay($api, $id, [['other', 'Start in May.']]);
    app(CallNotes::class)->summary($id);
    $second = $models->requests[1]->prompt->text();
    expect($second)->toContain("## The previous summary\nSalary talk: they offer 90,000, Me wants 300,000.")->toContain('Start in May.')->not->toContain('We offer 90,000.')
        ->and(app(LiveContext::class)->get($session)['summary'])->toBe('Salary talk, and a start date in May.');

    // The next suggestion reads it, in the running summary block.
    assistLive($api, $id, ['type' => 'context_update', 'settings' => ['assist' => 'on']]);
    assistLive($api, $id, ['type' => 'help_now']);
    expect(end($models->requests)->prompt->text())->toContain("## Running summary\nThe call so far, in short.\n\nSalary talk, and a start date in May.");
});

it('runs both as queued jobs that are tried once, so a retry never bills the model twice', function () {
    $facts = new UpdateLiveFactsJob('01JSESSION');
    $summary = new UpdateLiveSummaryJob('01JSESSION');

    expect($facts)->toBeInstanceOf(ShouldQueue::class)->and($facts->tries)->toBe(1)
        ->and($summary)->toBeInstanceOf(ShouldQueue::class)->and($summary->tries)->toBe(1);
});

it('runs the facts call as the queue worker does, once three lines are in', function () {
    // The sync queue of the tests: the job runs inside the request that carried the third line.
    $models = fakeAssistModels()->on('claude-haiku-5-5', ["Them: delivery is on Thursday\n", ['usage' => [500, 0, 0, 12]]]);
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'Hello there.'], ['me', 'Hi.']]);

    assistLive($api, $id, assistLine($id, 'Delivery is on Thursday.'));

    expect(app(LiveContext::class)->get(assistSessionOf($id))['facts'])->toBe(['Them: delivery is on Thursday'])
        ->and(AssistUsage::query()->where('assist_session_id', $id)->where('kind', 'facts')->count())->toBe(1)
        ->and(AssistUsage::query()->where('outcome', AssistBudget::PENDING)->count())->toBe(0);
});

it('writes nothing back for a session that ends while the model is being asked', function (string $what, Closure $over) {
    Queue::fake();
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistSay($api, $id, [['other', 'The budget is 8,000 USD.'], ['me', 'Good.'], ['other', 'Next, delivery.']]);
    $answer = $what === 'facts' ? "Them: the budget is 8,000 USD\n" : 'Budget talk.';
    // The call is in flight when the session ends (or is erased): its answer arrives after the context was cleared.
    $models->on('claude-haiku-5-5', [['do' => fn () => $over($api, $id)], $answer, ['usage' => [500, 0, 0, 12]]]);

    app(CallNotes::class)->{$what}($id);

    $session = AssistSession::query()->withoutGlobalScopes()->withTrashed()->find($id);
    expect(Cache::has('assist.live.context.'.$id))->toBeFalse()
        ->and(app(LiveContext::class)->get(assistSessionOf($id))['facts'])->toBe([])
        ->and(app(LiveContext::class)->get(assistSessionOf($id))['summary'])->toBe('')
        ->and($session->facts)->toBeNull()->and($session->facts_at)->toBeNull()->and($session->summary)->toBeNull()->and($session->summary_at)->toBeNull();
    // The model was asked and billed: what was spent is spent.
    expect(AssistUsage::query()->where('assist_session_id', $id)->where('kind', $what)->sole()->outcome)->toBe('done');
})->with([
    'facts, the session ends' => ['facts', fn ($api, $id) => $api->postJson("/api/assist/sessions/{$id}/end")->assertOk()],
    'summary, the session ends' => ['summary', fn ($api, $id) => $api->postJson("/api/assist/sessions/{$id}/end")->assertOk()],
    'facts, the session is erased' => ['facts', fn ($api, $id) => AssistRecords::eraseSession(assistSessionOf($id))],
    'summary, the session is erased' => ['summary', fn ($api, $id) => AssistRecords::eraseSession(assistSessionOf($id))],
    // Trashed on its own, with the context already cleared: the late answer must not bring it back.
    'facts, the session is trashed' => ['facts', function ($api, $id) {
        Cache::forget('assist.live.context.'.$id);
        assistSessionOf($id)->delete();
    }],
]);

it('takes no context update for a session that has ended or been erased', function () {
    [$api, $id] = httpSession($this);
    $session = assistSessionOf($id);
    expect(app(LiveContext::class)->update($session, ['memory' => 'Budget 5,000 USD.']))->toBeNull()
        ->and(app(LiveContext::class)->get($session)['memory'])->toBe('Budget 5,000 USD.');

    $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
    app(LiveContext::class)->update(assistSessionOf($id), ['memory' => 'Written late.']);
    app(LiveContext::class)->addFacts(assistSessionOf($id), ['Them: late']);
    app(LiveContext::class)->setSummary(assistSessionOf($id), 'Late summary.');

    expect(Cache::has('assist.live.context.'.$id))->toBeFalse();
});
