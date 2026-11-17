<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Pages\LiveAssist\AssistReportPage;
use App\Http\Controllers\Api\Assist\AssistSessionController;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Models\Device;
use App\Models\UsageLedgerEntry;
use App\Services\Assist\AssistAsk;
use App\Services\Assist\AssistAskAi;
use App\Services\Assist\AssistPlans;
use App\Services\Assist\AssistReportFailed;
use App\Services\Assist\ClaudeAssistReportAi;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Ask over the session (v1.9 L7): a question about one session, answered from its own lines, summary and facts, with
 * the moments it rests on; billed to the session's allowance as usage kind "ask"; nothing stored.
 */

beforeEach(fn () => assistRelaySetUp());

/** An ended Practice of [$device]'s person with two lines. */
function askableSession(Device $device, bool $practice = true): AssistSession
{
    $session = AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'device_id' => $device->id,
        'mode' => 'fast', 'plan' => 'pro', 'practice' => $practice, 'platform' => 'android', 'status' => 'active', 'started_at' => now()->subMinutes(5), 'summary' => 'Salary talk.']);
    AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 'q', 'channel' => 'other', 'start_ms' => 61000, 'end_ms' => 63000, 'text' => 'What salary do you expect?', 'is_question' => true]);
    AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 'a', 'channel' => 'me', 'start_ms' => 64000, 'end_ms' => 70000, 'text' => 'Around 300,000 rupees a month.']);
    AssistSessionController::close($session, now());

    return $session->fresh();
}

it('answers from the session\'s own numbered lines and cites its own moments, never the model\'s words', function () {
    $ai = fakeAssistAskAi();
    $device = assistPerson('pro');
    $session = askableSession($device);

    $answer = $this->api(Fx::token($device))->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'What salary did I ask for?'])->assertOk()->json();

    expect($answer)->toBe(['answer' => 'They expect 300,000 rupees.', 'citations' => [
        ['at_ms' => 61000, 'channel' => 'other', 'text' => 'What salary do you expect?'],
        ['at_ms' => 64000, 'channel' => 'me', 'text' => 'Around 300,000 rupees a month.'],
    ]]);
    expect($ai->inputs[0])->toMatchArray(['question' => 'What salary did I ask for?', 'practice' => true, 'summary' => 'Salary talk.'])
        ->and($ai->inputs[0]['lines'][1])->toBe(['n' => 2, 'at' => '01:04', 'who' => 'Me', 'text' => 'Around 300,000 rupees a month.']);
    // Billed to Practice's allowance as "ask": 2,000 in at $0.10 and 100 out at $0.50 per million.
    $usage = AssistUsage::query()->where('kind', 'ask')->sole();
    expect((float) $usage->cost_usd)->toBe(0.00025)->and($usage->assist_session_id)->toBe($session->id)->and($usage->estimated)->toBeFalse();
});

it('refuses when the worst case does not fit, when nothing was said, and says why the AI could not answer', function () {
    $device = assistPerson('pro');
    $api = $this->api(Fx::token($device));
    $session = askableSession($device);

    // Practice's $5 spent.
    AssistUsage::query()->create(['assist_session_id' => $session->id, 'workspace_id' => $device->workspace_id, 'user_id' => $device->user_id,
        'suggestion_id' => 'spent', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'fast', 'kind' => 'answer', 'cost_usd' => 5.0, 'occurred_at' => now()]);
    $ai = fakeAssistAskAi();
    $api->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'x'])->assertStatus(402)->assertJsonPath('code', 'budget_exhausted');
    expect($ai->inputs)->toBe([]);
    AssistUsage::query()->where('suggestion_id', 'spent')->delete();

    $empty = AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'mode' => 'fast',
        'practice' => true, 'platform' => 'android', 'status' => 'ended', 'started_at' => now(), 'ended_at' => now(), 'seconds_used' => 1]);
    $api->postJson("/api/assist/sessions/{$empty->id}/ask", ['question' => 'x'])->assertStatus(422);
    $api->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => str_repeat('a', 501)])->assertStatus(422);

    fakeAssistAskAi(fails: new AssistReportFailed(ClaudeAssistReportAi::NOT_SET_UP));
    $api->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'x'])->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
    // A failed call the provider billed is charged; one sent with no answer is charged at its worst case, estimated.
    fakeAssistAskAi(fails: new AssistReportFailed(ClaudeAssistReportAi::UNAVAILABLE, ['model' => 'claude-haiku-5-5', 'tokens' => ['input' => 1000, 'cached' => 0, 'written' => 0, 'output' => 0]]));
    $api->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'x'])->assertStatus(502)->assertJsonPath('code', 'provider_error');
    fakeAssistAskAi(fails: new AssistReportFailed(ClaudeAssistReportAi::UNAVAILABLE, unknownCost: true));
    $api->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'x'])->assertStatus(502);
    expect(AssistUsage::query()->where('kind', 'ask')->pluck('estimated')->all())->toBe([false, true]);
});

it('answers another person\'s session with 404 and asks nothing', function () {
    $ai = fakeAssistAskAi();
    $theirs = askableSession(assistPerson('pro'));

    $this->api(Fx::token(assistPerson('pro')))->postJson("/api/assist/sessions/{$theirs->id}/ask", ['question' => 'x'])->assertNotFound();
    expect($ai->inputs)->toBe([]);
});

it('asks from the console\'s report page and shows the answer with its moments, without storing it', function () {
    fakeAssistAskAi();
    $device = assistPerson('pro');
    $session = askableSession($device, practice: false);
    Filament::setCurrentPanel('app');
    $this->actingAs($device->user->fresh(), 'web');

    Livewire::test(AssistReportPage::class, ['session' => $session->id])
        ->callAction('ask', ['question' => 'What salary did I ask for?'])->assertHasNoActionErrors()
        ->assertSee('What salary did I ask for?')->assertSee('They expect 300,000 rupees.')->assertSee('1:04 · You');
    expect(AssistSession::query()->withoutGlobalScopes()->find($session->id)->report)->toBeNull();

    // An error is said in plain words, and nothing is shown.
    fakeAssistAskAi(fails: new AssistReportFailed(ClaudeAssistReportAi::UNAVAILABLE));
    Livewire::test(AssistReportPage::class, ['session' => $session->id])->callAction('ask', ['question' => 'Again?'])
        ->assertNotified('The AI could not answer right now. Try again in a minute.')->assertDontSee('Again?');
});

it('reserves the worst case first: of several asks at once with room for one, exactly one runs, and settling gives the rest back', function () {
    // An answer of up to 20,000 tokens at $0.50 per million: a worst case of 2 cents; 3 cents left of Practice's $5.
    config(['assist.ask.max_output_tokens' => 20000]);
    $device = assistPerson('pro');
    $session = askableSession($device);
    AssistUsage::query()->create(['assist_session_id' => $session->id, 'workspace_id' => $device->workspace_id, 'user_id' => $device->user_id,
        'suggestion_id' => 'spent', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'fast', 'kind' => 'answer', 'cost_usd' => 4.97, 'occurred_at' => now()]);
    $plans = app(AssistPlans::class);

    // While the first ask waits for the model, three more come from other devices: each finds the reservation.
    $fake = new class($session) implements AssistAskAi
    {
        /** @var list<string> */
        public array $others = [];

        public int $calls = 0;

        public ?int $leftDuring = null;

        public function __construct(private readonly AssistSession $session) {}

        public function promptText(array $input): string
        {
            return (string) json_encode($input);
        }

        public function ask(array $input): array
        {
            $this->calls++;
            $this->leftDuring = app(AssistPlans::class)->left($this->session->workspace_id, $this->session->user_id, true)['cents_left'];
            foreach (range(1, 3) as $other) {
                try {
                    app(AssistAsk::class)->ask($this->session, "At the same time {$other}?");
                    $this->others[] = 'answered';
                } catch (ApiError $e) {
                    $this->others[] = $e->apiCode();
                }
            }

            return ['data' => ['answer' => 'Once.', 'lines' => [1]], 'model' => 'claude-haiku-5-5', 'tokens' => ['input' => 2000, 'cached' => 0, 'written' => 0, 'output' => 100]];
        }
    };
    app()->instance(AssistAskAi::class, $fake);

    $this->api(Fx::token($device))->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'How much?'])->assertOk()->assertJsonPath('answer', 'Once.');

    expect($fake->calls)->toBe(1)->and($fake->others)->toBe(['budget_exhausted', 'budget_exhausted', 'budget_exhausted'])
        // While it ran, its 2-cent reservation held the room.
        ->and($fake->leftDuring)->toBe(1);
    // Settled to its real cost ($0.00025): the difference is back, and a new ask fits again.
    $row = AssistUsage::query()->where('kind', 'ask')->sole();
    expect((float) $row->cost_usd)->toBe(0.00025)->and($row->outcome)->toBe('done')->and($row->estimated)->toBeFalse()
        ->and($plans->left($device->workspace_id, $device->user_id, true)['cents_left'])->toBe(2)
        ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->where('report_id', 'assist/'.$row->suggestion_id)->value('cost_usd'))->toEqual('0.000250');
});

it('gives the reservation back when the call was never sent, and keeps it at the worst case when the answer was lost', function () {
    $device = assistPerson('pro');
    $session = askableSession($device);
    $api = $this->api(Fx::token($device));

    fakeAssistAskAi(fails: new AssistReportFailed(ClaudeAssistReportAi::UNAVAILABLE));
    $api->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'x'])->assertStatus(502);
    expect(AssistUsage::query()->where('kind', 'ask')->count())->toBe(0);

    fakeAssistAskAi(fails: new AssistReportFailed(ClaudeAssistReportAi::UNAVAILABLE, unknownCost: true));
    $api->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'x'])->assertStatus(502);
    $row = AssistUsage::query()->where('kind', 'ask')->sole();
    expect($row->outcome)->toBe('lost')->and($row->estimated)->toBeTrue()->and((float) $row->cost_usd)->toBeGreaterThan(0.0)
        ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->where('report_id', 'assist/'.$row->suggestion_id)->count())->toBe(1);
});

it('counts the Ask limit per person, whichever device asks', function () {
    fakeAssistAskAi();
    $device = assistPerson('pro');
    $session = askableSession($device);
    $phone = Fx::device($device->user, ['workspace_id' => $device->workspace_id]);
    foreach (range(1, 20) as $i) {
        $this->api(Fx::token($i % 2 === 0 ? $device : $phone))->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => "q{$i}"])->assertOk();
    }

    $this->api(Fx::token(Fx::device($device->user, ['workspace_id' => $device->workspace_id])))->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'one more'])
        ->assertStatus(429);
});

it('holds back what a session still on was granted by its token: Asks cannot spend it too', function () {
    config(['assist.ask.max_output_tokens' => 20000]); // a 2-cent worst case
    fakeAssistAskAi();
    $device = assistPerson('pro');
    $api = $this->api(Fx::token($device));
    $asked = askableSession($device);
    // 5 cents of Practice's $5 left.
    AssistUsage::query()->create(['assist_session_id' => $asked->id, 'workspace_id' => $device->workspace_id, 'user_id' => $device->user_id,
        'suggestion_id' => 'spent', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'fast', 'kind' => 'answer', 'cost_usd' => 4.95, 'occurred_at' => now()]);
    $plans = app(AssistPlans::class);

    // A Practice starts: its token grants the 5 cents, which the session keeps as its claim.
    $live = $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->assertCreated();
    $running = AssistSession::query()->withoutGlobalScopes()->find($live->json('session.id'));
    expect(relayClaims($live->json('relay.token'))['budget']['cents_left'])->toBe(5)->and($running->claimed_cents)->toBe(5)
        ->and($plans->left($device->workspace_id, $device->user_id, true)['cents_left'])->toBe(0);
    // Asks from every device at once find nothing left.
    foreach (range(1, 3) as $i) {
        $api->postJson("/api/assist/sessions/{$asked->id}/ask", ['question' => "q{$i}"])->assertStatus(402)->assertJsonPath('code', 'budget_exhausted');
    }

    // The relay spends 3 cents of it: 2 still held, nothing left for anyone else; a refresh grants the session what is left.
    $this->travel(10)->seconds();
    AssistUsage::query()->create(['assist_session_id' => $running->id, 'workspace_id' => $device->workspace_id, 'user_id' => $device->user_id,
        'suggestion_id' => 'relay-1', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'fast', 'kind' => 'answer', 'cost_usd' => 0.03, 'occurred_at' => now()]);
    expect($plans->left($device->workspace_id, $device->user_id, true)['cents_left'])->toBe(0)
        ->and(relayClaims($api->postJson("/api/assist/sessions/{$running->id}/token")->assertOk()->json('relay.token'))['budget']['cents_left'])->toBe(2);
    $this->travel(10)->seconds();
    $api->postJson("/api/assist/sessions/{$asked->id}/ask", ['question' => 'now?'])->assertStatus(402);

    // Once it ends, the 2 cents are free again.
    $api->postJson("/api/assist/sessions/{$running->id}/end")->assertOk();
    expect($plans->left($device->workspace_id, $device->user_id, true)['cents_left'])->toBe(2);
    $api->postJson("/api/assist/sessions/{$asked->id}/ask", ['question' => 'now?'])->assertOk();
});

it('holds nothing for a token past its lifetime', function () {
    $device = assistPerson('pro');
    $id = $this->api(Fx::token($device))->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->json('session.id');
    $plans = app(AssistPlans::class);
    expect($plans->left($device->workspace_id, $device->user_id, true)['cents_left'])->toBe(0);

    $this->travel(16)->minutes();
    expect($plans->left($device->workspace_id, $device->user_id, true)['cents_left'])->toBe(500)
        ->and(AssistSession::query()->withoutGlobalScopes()->find($id)->status)->toBe('active');
});

it('cites only lines the model was given', function () {
    config(['assist.ask.transcript_chars' => 120]);
    $device = assistPerson('pro');
    $session = askableSession($device);
    foreach (range(3, 12) as $n) {
        AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => "x{$n}", 'channel' => 'other', 'start_ms' => 70000 + $n * 1000, 'end_ms' => 70500 + $n * 1000, 'text' => "Line {$n} of the middle."]);
    }
    // The model names line 6, from the middle it was never shown, and line 1.
    $ai = fakeAssistAskAi(['answer' => 'Somewhere.', 'lines' => [6, 1]]);

    $answer = $this->api(Fx::token($device))->postJson("/api/assist/sessions/{$session->id}/ask", ['question' => 'x'])->assertOk()->json();
    expect(array_column($ai->inputs[0]['lines'], 'n'))->not->toContain(6)
        ->and($answer['citations'])->toBe([['at_ms' => 61000, 'channel' => 'other', 'text' => 'What salary do you expect?']]);
});
