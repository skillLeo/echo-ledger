<?php

declare(strict_types=1);

use App\Filament\Pages\LiveAssist\AssistReportPage;
use App\Http\Controllers\Api\Assist\AssistSessionController;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Models\Device;
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
