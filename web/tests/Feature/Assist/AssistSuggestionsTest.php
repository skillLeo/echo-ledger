<?php

declare(strict_types=1);

use App\Models\AssistSuggestion;
use App\Models\AssistUsage;
use App\Models\UsageLedgerEntry;
use App\Services\Assist\AssistBudget;
use App\Services\Assist\AssistTransport;
use App\Services\Assist\Http\LiveContext;
use App\Services\Assist\Http\LiveState;
use App\Services\Assist\Http\Pricing;
use App\Services\SettingsRepository;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Security\Support\ApiClient;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Suggestions in HTTP mode (1.9.0-q): one per final turn of the other side, streamed as the relay streams them, with
 * the relay's payloads, fallbacks, held answers, SKIP, the Balanced upgrade, the budget gate and the usage ledger.
 */

beforeEach(function () {
    assistRelaySetUp();
    app(SettingsRepository::class)->set('assist.connection', AssistTransport::SHARED);
    config(['assist.http.hold_max_ms' => 300, 'assist.http.hold_poll_ms' => 10, 'assist.http.card_check_ms' => 0]);
});

const ASSIST_QUESTION = ['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'other', 'text' => 'What is your notice period?', 'start_ms' => 1000, 'end_ms' => 4000];

it('streams a suggestion for the other side\'s question with the relay\'s payloads', function () {
    $models = fakeAssistModels()->on('claude-haiku-5-5', ['Yes, ', 'one month.', ['usage' => [300, 4000, 0, 31]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'token', 'suggestion_end', 'end']);
    [$start, $one, $two, $end] = array_column($events, 'data');
    expect($start)->toMatchArray(['type' => 'suggestion_start', 'seq' => 1, 'turn_id' => 'o-1', 'kind' => 'answer', 'mode' => 'balanced', 'model' => 'claude-haiku-5-5', 'speculative' => false, 'draft' => false, 'replaces' => null])
        ->and($start['suggestion_id'])->toBeString()->not->toBe('')
        ->and([$one['text'], $two['text']])->toBe(['Yes, ', 'one month.'])
        ->and($one['suggestion_id'])->toBe($start['suggestion_id'])
        ->and($end)->toMatchArray(['suggestion_id' => $start['suggestion_id'], 'status' => 'done', 'model' => 'claude-haiku-5-5', 'seq' => 4])
        ->and(array_keys($end['latency']))->toBe(['end_of_turn_to_request_ms', 'request_to_first_token_ms', 'end_of_turn_to_first_token_ms', 'relay_overhead_ms', 'total_ms'])
        // 300 x $0.10 + 4,000 x $0.01 + 31 x $0.50 per million = 85.5 micro-dollars, rounded up as the relay does.
        ->and($end['usage'])->toBe(['input_tokens' => 300, 'cached_input_tokens' => 4000, 'cache_write_tokens' => 0, 'output_tokens' => 31, 'cost_usd' => 8.6e-5, 'estimated' => false])
        ->and($models->asked())->toBe(['claude-haiku-5-5']);
    // The request is the pack's prompt: the rules first, the question last.
    $prompt = $models->requests[0]->prompt;
    expect($prompt->text())->toContain('Live Assist: system rules')->toEndWith("What is your notice period?\n");
});

it('bills the suggestion to the usage ledger as live_assist and keeps its words for the report', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['Yes, ', 'one month.', ['usage' => [300, 4000, 0, 31]]]);
    [$api, $id] = httpSession($this);

    $suggestion = assistLive($api, $id, ASSIST_QUESTION)[0]['data']['suggestion_id'];

    $usage = AssistUsage::query()->where('assist_session_id', $id)->sole();
    expect($usage->suggestion_id)->toBe($suggestion)->and($usage->kind)->toBe('answer')->and($usage->outcome)->toBe('done')->and($usage->model)->toBe('claude-haiku-5-5')
        ->and($usage->provider)->toBe('anthropic')->and($usage->estimated)->toBeFalse()->and($usage->fallback)->toBeFalse()
        ->and([$usage->input_tokens, $usage->cached_tokens, $usage->written_tokens, $usage->output_tokens])->toBe([300, 4000, 0, 31])
        ->and((float) $usage->cost_usd)->toBe(0.000086)->and($usage->latency)->toHaveKeys(['total_ms']);
    $ledger = UsageLedgerEntry::query()->where('report_id', 'assist/'.$suggestion)->sole();
    expect($ledger->use_case)->toBe('live_assist')->and((float) $ledger->cost_usd)->toBe(0.000086)->and($ledger->input_tokens)->toBe(4300)->and($ledger->output_tokens)->toBe(31);
    $kept = AssistSuggestion::query()->where('assist_session_id', $id)->sole();
    expect($kept->suggestion_id)->toBe($suggestion)->and($kept->turn_id)->toBe('o-1')->and($kept->kind)->toBe('answer')->and($kept->text)->toBe('Yes, one month.')->and($kept->replaces)->toBeNull();
    // Nothing is left reserved.
    expect(AssistUsage::query()->where('outcome', AssistBudget::PENDING)->count())->toBe(0);
});

it('starts nothing for a reply that needs no answer, for Me, or while suggestions are paused', function () {
    // The facts call that the third line starts is another test's (AssistNotesTest): the queue is not run here.
    Queue::fake();
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);

    expect(assistNames(assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-2', 'text' => 'Okay.'])))->toBe(['end'])
        ->and(assistNames(assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'm-1', 'channel' => 'me', 'text' => 'One month.'])))->toBe(['end']);
    assistLive($api, $id, ['type' => 'context_update', 'settings' => ['assist' => 'paused']]);
    expect(assistNames(assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-3'])))->toBe(['end'])
        ->and($models->requests)->toBe([]);
    // Help now still works while paused, and the question is not asked twice when the app retries it.
    assistLive($api, $id, ['type' => 'context_update', 'settings' => ['assist' => 'on']]);
    expect(assistNames(assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-4'])))->toContain('suggestion_start')
        ->and(assistNames(assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-4'])))->toBe(['end'])
        ->and($models->asked())->toHaveCount(1);
});

it('holds back SKIP: no words, a skip with its usage, and the reservation settled', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['S', 'KIP', ['usage' => [200, 0, 0, 2]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($events))->toBe(['suggestion_start', 'skip', 'end'])
        ->and($events[1]['data'])->toMatchArray(['suggestion_id' => $events[0]['data']['suggestion_id'], 'turn_id' => 'o-1'])
        ->and($events[1]['data']['usage'])->toMatchArray(['input_tokens' => 200, 'output_tokens' => 2, 'estimated' => false]);
    expect(AssistUsage::query()->where('assist_session_id', $id)->sole()->outcome)->toBe('skip')
        ->and(AssistSuggestion::query()->count())->toBe(0);
});

it('sends a real answer that starts like SKIP as soon as it differs, held words and all', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['S', 'ure, yes.', ['usage' => [200, 0, 0, 4]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end'])
        ->and($events[1]['data']['text'])->toBe('Sure, yes.');
});

it('goes to the fallback model when the first answers nothing in time, as one suggestion', function () {
    $models = fakeAssistModels()
        ->on('claude-haiku-5-5', [['fail' => 'timeout']])
        ->on('openai/gpt-oss-120b', ['One month.', ['usage' => [250, 0, 0, 20]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end'])
        ->and($events[0]['data']['model'])->toBe('claude-haiku-5-5')
        ->and($events[2]['data']['model'])->toBe('openai/gpt-oss-120b')
        // The attempt given up is in the usage, an estimate: the prompt as input and nothing out.
        ->and($events[2]['data']['usage']['estimated'])->toBeTrue()
        ->and($events[2]['data']['usage']['input_tokens'])->toBeGreaterThan(250)
        ->and($models->asked())->toBe(['claude-haiku-5-5', 'openai/gpt-oss-120b']);
    $usage = AssistUsage::query()->where('assist_session_id', $id)->sole();
    expect($usage->fallback)->toBeTrue()->and($usage->model)->toBe('openai/gpt-oss-120b')->and($usage->estimated)->toBeTrue()->and($usage->outcome)->toBe('done');
});

it('ends a suggestion whose model broke after its first words as superseded and starts the fallback\'s as its replacement', function () {
    fakeAssistModels()
        ->on('claude-haiku-5-5', ['Yes, I ', ['fail' => 'unavailable']])
        ->on('openai/gpt-oss-120b', ['One month.', ['usage' => [250, 0, 0, 20]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'suggestion_end', 'suggestion_start', 'token', 'suggestion_end', 'end']);
    [$first, , $superseded, $second, , $done] = array_column($events, 'data');
    expect($superseded)->toMatchArray(['suggestion_id' => $first['suggestion_id'], 'status' => 'superseded', 'model' => 'claude-haiku-5-5'])
        ->and($second)->toMatchArray(['kind' => 'answer', 'model' => 'openai/gpt-oss-120b', 'replaces' => $first['suggestion_id'], 'turn_id' => 'o-1'])
        ->and($done)->toMatchArray(['suggestion_id' => $second['suggestion_id'], 'status' => 'done']);
    $rows = AssistUsage::query()->where('assist_session_id', $id)->orderBy('id')->get();
    expect($rows->pluck('outcome')->all())->toBe(['superseded', 'done'])->and($rows[1]->fallback)->toBeTrue()
        ->and(AssistSuggestion::query()->sole()->replaces)->toBe($first['suggestion_id']);
});

it('ends with an error naming the suggestion when both models fail, and bills only what was taken', function () {
    fakeAssistModels()
        ->on('claude-haiku-5-5', [['fail' => 'rate_limited']])
        ->on('openai/gpt-oss-120b', [['fail' => 'timeout']]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($events))->toBe(['suggestion_start', 'error', 'end'])
        ->and($events[1]['data'])->toMatchArray(['code' => 'provider_timeout', 'retry' => true, 'suggestion_id' => $events[0]['data']['suggestion_id'], 'in_reply_to' => null, 'fatal' => false])
        ->and($events[1]['data']['message'])->toBe('The model did not answer in time. Try again.');
    // The rate-limited attempt cost nothing; the timed-out one is billed as an estimate.
    $usage = AssistUsage::query()->where('assist_session_id', $id)->sole();
    expect($usage->outcome)->toBe('error')->and($usage->estimated)->toBeTrue()->and($usage->input_tokens)->toBeGreaterThan(0);
});

it('releases the reservation when no provider took the request, and says the server is not set up without a vendor\'s name', function () {
    // No fake: the real models, and no provider key in the vault.
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($events))->toBe(['suggestion_start', 'error', 'end'])
        ->and($events[1]['data'])->toMatchArray(['code' => 'provider_not_configured', 'retry' => false, 'message' => 'Live Assist is not set up on the server yet. Ask your admin.'])
        ->and(AssistUsage::query()->where('assist_session_id', $id)->count())->toBe(0);
});

it('shows a Sonnet answer only when it is complete, and never a refused one', function () {
    $models = fakeAssistModels()
        ->on('claude-sonnet-5-5', ['I will ', 'tell you ', ['fail' => 'declined']])
        ->on('claude-haiku-5-5', ['A safe answer.', ['usage' => [200, 0, 0, 10]]]);
    [$api, $id] = httpSession($this, 'business', ['mode' => 'deep']);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    // Nothing of the refused words reached the app; the fallback's answer did, as one suggestion.
    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end'])
        ->and($events[0]['data'])->toMatchArray(['mode' => 'deep', 'model' => 'claude-sonnet-5-5'])
        ->and($events[1]['data']['text'])->toBe('A safe answer.')
        ->and($events[2]['data']['model'])->toBe('claude-haiku-5-5');

    $models->on('claude-sonnet-5-5', ['Yes, ', 'one month ', 'is fine.', ['usage' => [500, 0, 0, 30]]]);
    $events = assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-2', 'text' => 'And the start date?']);
    // A held answer comes whole: one token with all the words.
    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end'])
        ->and($events[1]['data']['text'])->toBe('Yes, one month is fine.')
        ->and($events[2]['data']['model'])->toBe('claude-sonnet-5-5');
});

const ASSIST_HARD = ['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'other', 'text' => 'Why did you leave your last job, and what would you do differently now?', 'start_ms' => 1000, 'end_ms' => 6000];

it('sends a hard question in Balanced to the upgrade model too, and the upgrade replaces the draft with its whole answer', function () {
    $models = fakeAssistModels()
        ->on('claude-haiku-5-5', ['Draft answer.', ['usage' => [300, 0, 0, 10]]])
        ->on('claude-sonnet-5-5', ['Yes, ', 'it holds for sixty days.', ['usage' => [900, 0, 0, 40]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_HARD);

    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'suggestion_end', 'suggestion_start', 'token', 'suggestion_end', 'end']);
    [$draft, , $draftEnd, $upgrade, $whole, $upgradeEnd] = array_column($events, 'data');
    expect($draft)->toMatchArray(['kind' => 'answer', 'draft' => true, 'model' => 'claude-haiku-5-5', 'replaces' => null])
        ->and($draftEnd['status'])->toBe('done')
        ->and($upgrade)->toMatchArray(['kind' => 'upgrade', 'draft' => false, 'speculative' => false, 'model' => 'claude-sonnet-5-5', 'replaces' => $draft['suggestion_id'], 'turn_id' => 'o-1', 'mode' => 'balanced'])
        ->and($whole['text'])->toBe('Yes, it holds for sixty days.')
        ->and($upgradeEnd)->toMatchArray(['suggestion_id' => $upgrade['suggestion_id'], 'status' => 'done', 'model' => 'claude-sonnet-5-5'])
        ->and($models->asked())->toBe(['claude-haiku-5-5', 'claude-sonnet-5-5']);
    $usage = AssistUsage::query()->where('assist_session_id', $id)->orderBy('id')->get();
    expect($usage->pluck('kind')->all())->toBe(['answer', 'upgrade'])->and($usage->pluck('outcome')->all())->toBe(['done', 'done'])
        ->and(AssistSuggestion::query()->orderBy('id')->get()->pluck('replaces')->all())->toBe([null, $draft['suggestion_id']]);
    // Sonnet: 900 x $2 + 40 x $10 per million.
    expect((float) $usage[1]->cost_usd)->toBe(0.0022);
});

it('leaves the upgrade out when Me has started speaking, the draft is SKIP, or nothing is left to spend on it', function () {
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    $session = assistSessionOf($id);

    // Me speaks while the draft is written: the moment has passed.
    $models->on('claude-haiku-5-5', ['Draft.', ['do' => fn () => app(LiveContext::class)->meSpoke($session, (int) floor(microtime(true) * 1000) + 5)], ['usage' => [300, 0, 0, 10]]]);
    expect(assistNames(assistLive($api, $id, ASSIST_HARD)))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end']);

    // A draft that is SKIP needs no upgrade.
    $models->on('claude-haiku-5-5', ['SKIP', ['usage' => [300, 0, 0, 2]]]);
    expect(assistNames(assistLive($api, $id, [...ASSIST_HARD, 'turn_id' => 'o-2'])))->toBe(['suggestion_start', 'skip', 'end']);

    expect($models->asked())->toBe(['claude-haiku-5-5', 'claude-haiku-5-5']);
    // The upgrades' reservations went back: only the two drafts were billed, and nothing is pending.
    expect(AssistUsage::query()->where('assist_session_id', $id)->pluck('kind')->all())->toBe(['answer', 'answer'])
        ->and(AssistUsage::query()->where('outcome', AssistBudget::PENDING)->count())->toBe(0);
});

it('says nothing of an upgrade that fails or answers SKIP, and bills what it took', function () {
    fakeAssistModels()
        ->on('claude-haiku-5-5', ['Draft one.', ['usage' => [300, 0, 0, 10]]])
        ->on('claude-haiku-5-5', ['Draft two.', ['usage' => [300, 0, 0, 10]]])
        ->on('claude-sonnet-5-5', [['fail' => 'timeout']])
        ->on('claude-sonnet-5-5', ['SKIP', ['usage' => [900, 0, 0, 2]]]);
    [$api, $id] = httpSession($this);

    expect(assistNames(assistLive($api, $id, ASSIST_HARD)))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end']);
    expect(assistNames(assistLive($api, $id, [...ASSIST_HARD, 'turn_id' => 'o-2'])))->toBe(['suggestion_start', 'token', 'suggestion_end', 'end']);

    $upgrades = AssistUsage::query()->where('assist_session_id', $id)->where('kind', 'upgrade')->orderBy('id')->get();
    expect($upgrades->pluck('outcome')->all())->toBe(['error', 'discarded'])->and($upgrades[0]->estimated)->toBeTrue()->and($upgrades[1]->estimated)->toBeFalse();
});

it('drops the upgrade when it does not fit beside the draft, and the draft still answers', function () {
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    // A long document: the upgrade model's worst case is about six cents, the draft's one; five cents are left.
    assistLive($api, $id, ['type' => 'context_update', 'documents' => [['id' => 'cv', 'title' => 'CV', 'text' => str_repeat('Payments platform engineer. ', 2200)]]]);
    assistSpend($id, 14.95);

    $events = assistLive($api, $id, ASSIST_HARD);

    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'token', 'suggestion_end', 'end'])
        ->and($events[0]['data'])->toMatchArray(['draft' => false, 'mode' => 'balanced'])
        ->and($models->asked())->toBe(['claude-haiku-5-5']);
});

it('ends the suggestion on the card as superseded, before the new one starts, and its writer stops without a word', function () {
    [$api, $id] = httpSession($this);
    $inner = [];
    $models = fakeAssistModels()->on('claude-haiku-5-5', [
        'I promise delivery ',
        ['do' => function () use ($api, $id, &$inner) {
            $inner = assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-2', 'text' => 'And the start date?']);
        }],
        'tomorrow.',
        ['usage' => [300, 0, 0, 10]],
    ]);

    $outer = assistLive($api, $id, ASSIST_QUESTION);

    // The second question's stream: the end of the first suggestion (an estimate), then its own start, its words, its end.
    expect(assistNames($inner))->toBe(['suggestion_end', 'suggestion_start', 'token', 'token', 'suggestion_end', 'end']);
    [$ended, $started] = array_column($inner, 'data');
    expect($ended)->toMatchArray(['suggestion_id' => $outer[0]['data']['suggestion_id'], 'status' => 'superseded', 'model' => 'claude-haiku-5-5'])
        ->and($ended['usage']['estimated'])->toBeTrue()->and($ended['usage']['input_tokens'])->toBeGreaterThan(100)
        ->and($started)->toMatchArray(['turn_id' => 'o-2', 'replaces' => null])
        ->and($started['suggestion_id'])->not->toBe($outer[0]['data']['suggestion_id']);
    // The first stream wrote only what it had before the card was taken, and said nothing about its end.
    expect(assistNames($outer))->toBe(['suggestion_start', 'token', 'end'])
        ->and($outer[1]['data']['text'])->toBe('I promise delivery ');
    $rows = AssistUsage::query()->where('assist_session_id', $id)->orderBy('id')->get();
    expect($rows->pluck('outcome')->sort()->values()->all())->toBe(['done', 'superseded'])
        // The writer settled for real: nothing was sent to it by the provider, so its own estimate.
        ->and($rows->firstWhere('suggestion_id', $outer[0]['data']['suggestion_id'])->estimated)->toBeTrue();
    expect(AssistSuggestion::query()->pluck('suggestion_id')->all())->toBe([$started['suggestion_id']]);
});

it('cancels the suggestion on the card: the cancel stream sends the end, the writer stops silently', function () {
    [$api, $id] = httpSession($this);
    $inner = [];
    fakeAssistModels()->on('claude-haiku-5-5', [
        'Yes, ',
        ['do' => function () use ($api, $id, &$inner) {
            $inner = assistLive($api, $id, ['type' => 'cancel']);
        }],
        'one month.',
        ['usage' => [300, 0, 0, 10]],
    ]);

    $outer = assistLive($api, $id, ASSIST_QUESTION);

    expect(assistNames($inner))->toBe(['suggestion_end', 'end'])
        ->and($inner[0]['data'])->toMatchArray(['suggestion_id' => $outer[0]['data']['suggestion_id'], 'status' => 'cancelled'])
        ->and($inner[0]['data']['usage']['estimated'])->toBeTrue()
        ->and(assistNames($outer))->toBe(['suggestion_start', 'token', 'end']);
    expect(AssistUsage::query()->where('assist_session_id', $id)->sole()->outcome)->toBe('cancelled')
        ->and(AssistSuggestion::query()->count())->toBe(0);
    // Nothing is writing now: a second cancel says only that the stream ends.
    expect(assistNames(assistLive($api, $id, ['type' => 'cancel'])))->toBe(['end']);
});

it('cancels a suggestion by its id only, and a finished one is not cancelled', function () {
    [$api, $id] = httpSession($this);
    $inner = [];
    fakeAssistModels()->on('claude-haiku-5-5', [
        'Yes, ',
        ['do' => function () use ($api, $id, &$inner) {
            $inner['other'] = assistLive($api, $id, ['type' => 'cancel', 'suggestion_id' => 'not-this-one']);
            $inner['own'] = assistLive($api, $id, ['type' => 'cancel', 'suggestion_id' => 'x']);
        }],
        'one month.',
        ['usage' => [300, 0, 0, 10]],
    ]);

    $outer = assistLive($api, $id, ASSIST_QUESTION);

    // Another id on the card's suggestion changes nothing: it finishes as usual.
    expect(assistNames($inner['other']))->toBe(['end'])->and(assistNames($outer))->toBe(['suggestion_start', 'token', 'token', 'suggestion_end', 'end']);
    expect(assistNames(assistLive($api, $id, ['type' => 'cancel', 'suggestion_id' => $outer[0]['data']['suggestion_id']])))->toBe(['end']);
});

/** Runs [$do] the [$nth] time a held question looks at how many finals the other side has sent (each look is one poll). */
function assistOnPoll(string $id, int $nth, Closure $do): void
{
    $polls = 0;
    $listener = function (CacheHit|CacheMissed $e) use ($id, $nth, $do, &$polls): void {
        if ($e->key === 'assist.live.other.'.$id && ++$polls === $nth) {
            $do();
        }
    };
    Event::listen(CacheHit::class, $listener);
    Event::listen(CacheMissed::class, $listener);
}

it('holds a question the budget cannot take yet, quiet but for keep-alives, and starts it when calls in flight finish', function () {
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    $blocker = assistHoldAllBudget($id);
    assistOnPoll($id, 3, fn () => $blocker->delete());

    $body = $api->postJson("/api/assist/sessions/{$id}/live", ASSIST_QUESTION)->assertOk()->streamedContent();

    expect($body)->toStartWith(": keep-alive\n\n")
        ->and(assistNames(events($body)))->toBe(['suggestion_start', 'token', 'token', 'suggestion_end', 'end'])
        ->and($models->asked())->toBe(['claude-haiku-5-5']);
    expect(AssistUsage::query()->where('outcome', AssistBudget::PENDING)->count())->toBe(0);
});

it('drops a held question when Me speaks, when the other side says more, and after hold_max_ms', function () {
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    $session = assistSessionOf($id);
    assistHoldAllBudget($id);

    // Me speaks while it waits.
    assistOnPoll($id, 2, fn () => app(LiveContext::class)->meSpoke($session, (int) floor(microtime(true) * 1000) + 5));
    $body = $api->postJson("/api/assist/sessions/{$id}/live", ASSIST_QUESTION)->assertOk()->streamedContent();
    expect(assistNames(events($body)))->toBe(['end'])->and($body)->toContain(': keep-alive');

    // The other side sends another final while it waits: the older question is dropped, the newer one is held in turn.
    Event::forget(CacheMissed::class);
    Event::forget(CacheHit::class);
    app(LiveContext::class)->forget($session);
    $first = fn () => app(LiveState::class)->otherFinalSent($session);
    assistOnPoll($id, 2, $first);
    expect(assistNames(events($api->postJson("/api/assist/sessions/{$id}/live", [...ASSIST_QUESTION, 'turn_id' => 'o-2'])->assertOk()->streamedContent())))->toBe(['end']);

    // Nothing frees the budget: after hold_max_ms the stream ends with nothing, and no message.
    Event::forget(CacheMissed::class);
    Event::forget(CacheHit::class);
    $started = microtime(true);
    $events = assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-3']);
    expect(assistNames($events))->toBe(['end'])->and(microtime(true) - $started)->toBeGreaterThan(0.25)->and($models->requests)->toBe([]);
});

it('does not hold what the person asked for: help now hears that there is no budget free right now', function () {
    fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-1']);
    assistHoldAllBudget($id);

    $events = assistLive($api, $id, ['type' => 'help_now']);

    expect(assistNames($events))->toBe(['error', 'end'])
        ->and($events[0]['data'])->toMatchArray(['code' => 'budget_exhausted', 'retry' => true, 'suggestion_id' => null, 'fatal' => false, 'message' => 'Not enough budget is free for a suggestion right now. Try again in a moment.']);
});

it('lets the next cheaper mode answer this one question, with no mode_changed, when the mode\'s worst case does not fit', function () {
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this, 'business', ['mode' => 'deep']);
    // A document of about 15,000 tokens: Sonnet's worst case is several cents, Haiku's one; three cents are left, enough for
    // the mode's usual reserve at the typical size but not for this suggestion's worst case.
    assistLive($api, $id, ['type' => 'context_update', 'documents' => [['id' => 'cv', 'title' => 'CV', 'text' => str_repeat('Payments platform engineer. ', 2200)]]]);
    assistSpend($id, 59.97);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    // No mode_changed before it starts; the cost of the answer then moves the budget's mode, which the stream says.
    expect(assistNames($events))->toBe(['suggestion_start', 'token', 'token', 'suggestion_end', 'mode_changed', 'end'])
        ->and($events[0]['data'])->toMatchArray(['mode' => 'balanced', 'model' => 'claude-haiku-5-5'])
        ->and($events[4]['data'])->toMatchArray(['from' => 'deep', 'to' => 'balanced', 'reason' => 'budget'])
        ->and($models->asked())->toBe(['claude-haiku-5-5']);
});

it('tells the app when the budget moves the mode, once, and answers in the mode the budget allows', function () {
    fakeAssistModels();
    [$api, $id] = httpSession($this);
    // One cent is left: three Balanced suggestions with their upgrades no longer fit, one Fast one does.
    assistSpend($id, 14.99);

    $events = assistLive($api, $id, ASSIST_QUESTION);

    // The suggestion's own cost takes the last of the cent: captions only, which the same stream says.
    expect(assistNames($events))->toBe(['mode_changed', 'suggestion_start', 'token', 'token', 'suggestion_end', 'mode_changed', 'end'])
        ->and($events[0]['data'])->toMatchArray(['type' => 'mode_changed', 'seq' => 1, 'from' => 'balanced', 'to' => 'fast', 'reason' => 'budget'])
        ->and($events[1]['data'])->toMatchArray(['mode' => 'fast'])
        ->and($events[5]['data'])->toMatchArray(['from' => 'fast', 'to' => 'captions_only', 'reason' => 'budget']);

    // It is said once: the next question finds the app told.
    expect(assistNames(assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-2'])))->toBe(['end']);
});

it('turns suggestions off at the end of the budget: a message for what was asked for, nothing for the other side\'s turn', function () {
    $models = fakeAssistModels();
    [$api, $id] = httpSession($this);
    assistSpend($id, 15);

    $turn = assistLive($api, $id, ASSIST_QUESTION);
    $help = assistLive($api, $id, ['type' => 'help_now']);

    expect(assistNames($turn))->toBe(['mode_changed', 'end'])
        ->and($turn[0]['data'])->toMatchArray(['from' => 'balanced', 'to' => 'captions_only', 'reason' => 'budget'])
        ->and(assistNames($help))->toBe(['error', 'end'])
        ->and($help[0]['data'])->toMatchArray(['code' => 'budget_exhausted', 'retry' => false, 'message' => 'Suggestions are off: this session\'s budget is used up. Captions continue.'])
        ->and($models->requests)->toBe([]);
});

it('reserves the worst case for Urdu: every character of it a token', function () {
    $reserved = [];
    fakeAssistModels()->on('claude-haiku-5-5', [
        ['do' => function () use (&$reserved) {
            $reserved[] = (float) AssistUsage::query()->where('outcome', AssistBudget::PENDING)->where('kind', 'answer')->sole()->cost_usd;
        }],
        'Yes.', ['usage' => [300, 0, 0, 10]],
    ])->on('claude-haiku-5-5', [
        ['do' => function () use (&$reserved) {
            $reserved[] = (float) AssistUsage::query()->where('outcome', AssistBudget::PENDING)->where('kind', 'answer')->sole()->cost_usd;
        }],
        'Yes.', ['usage' => [300, 0, 0, 10]],
    ]);
    [$api, $id] = httpSession($this);

    // The same length of notes, in English and in Urdu.
    assistLive($api, $id, ['type' => 'context_update', 'documents' => [['id' => 'n', 'title' => 'Notes', 'text' => mb_substr(str_repeat('Delivery is on Thursday. ', 4400), 0, 100000)]]]);
    assistLive($api, $id, ASSIST_QUESTION);
    assistLive($api, $id, ['type' => 'context_update', 'documents' => [['id' => 'n', 'title' => 'Notes', 'text' => mb_substr(str_repeat('ڈیلیوری جمعرات کو ہے۔ ', 4600), 0, 100000)]]]);
    assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-2']);

    // Every byte of Urdu is a token at worst (an Urdu letter is two UTF-8 bytes: about 180,000 tokens here with the tenth
    // on top, billed on the long-context card above its threshold), where the same length of English is about a third
    // of the characters (two cents).
    expect($reserved[0])->toBe(0.02)->and($reserved[1])->toBe(0.15);
});

it('keeps an all-Urdu context under its reservation, even if every byte were a token', function () {
    foreach (['ڈیلیوری جمعرات کو ہے۔ ', 'قیمت ۱۲۵۰۰ روپے، ادائیگی پہلے۔ ', 'کیا آپ نے دستخط شدہ معاہدہ بھیج دیا؟ '] as $line) {
        $text = str_repeat($line, 500);
        expect(Pricing::upperTokens($text))->toBeGreaterThanOrEqual(strlen($text));
    }
});

it('bounds Urdu and Arabic by their UTF-8 bytes, English at three characters a token, with a tenth on top', function () {
    expect(Pricing::upperTokens(str_repeat('a', 300)))->toBe(110)
        ->and(Pricing::upperTokens(str_repeat('ک', 300)))->toBe(660)
        ->and(Pricing::upperTokens(str_repeat('ك', 100).str_repeat('a', 30)))->toBe(231)
        ->and(Pricing::estimateTokens(str_repeat('ک', 300)))->toBe(150);
});

it('settles each suggestion to its real cost so that the month\'s spend is what was billed', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['A.', ['usage' => [1000, 0, 0, 100]]])->on('claude-haiku-5-5', ['B.', ['usage' => [2000, 0, 0, 200]]]);
    [$api, $id] = httpSession($this);

    assistLive($api, $id, ASSIST_QUESTION);
    assistLive($api, $id, [...ASSIST_QUESTION, 'turn_id' => 'o-2']);

    // 1,000 x $0.10 + 100 x $0.50, and twice that, per million.
    expect(round((float) AssistUsage::query()->where('assist_session_id', $id)->sum('cost_usd'), 6))->toBe(0.00045)
        ->and(round((float) UsageLedgerEntry::query()->where('use_case', 'live_assist')->sum('cost_usd'), 6))->toBe(0.00045)
        ->and(AssistUsage::query()->where('assist_session_id', $id)->where('outcome', AssistBudget::PENDING)->count())->toBe(0);
});

it('settles at its worst case a suggestion whose stream was cut, and keeps a reservation still within its time', function () {
    [$api, $id] = httpSession($this);
    $session = assistSessionOf($id);
    $budget = app(AssistBudget::class);
    $stale = $budget->reserve($session, 'answer', 'claude-haiku-5-5', 3, 'cut-stream');
    $stale->forceFill(['occurred_at' => now()->subMinutes(5)])->save();
    $fresh = $budget->reserve($session, 'help_now', 'claude-haiku-5-5', 2, 'running-stream');

    $this->artisan('assist:sweep')->assertSuccessful();

    expect($stale->fresh()->outcome)->toBe('lost')->and($stale->fresh()->estimated)->toBeTrue()->and((float) $stale->fresh()->cost_usd)->toBe(0.03)
        ->and($fresh->fresh()->outcome)->toBe(AssistBudget::PENDING)
        ->and(UsageLedgerEntry::query()->where('report_id', 'assist/cut-stream')->count())->toBe(1);
});

it('forgets what the call told the server when the session ends', function () {
    [$api, $id] = httpSession($this);
    assistLive($api, $id, ['type' => 'context_update', 'brief' => ['title' => 'Interview'], 'memory' => 'Budget 5,000 USD.']);
    expect(app(LiveContext::class)->get(assistSessionOf($id))['memory'])->toBe('Budget 5,000 USD.');

    $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();

    expect(app(LiveContext::class)->get(assistSessionOf($id))['memory'])->toBe('');
});
