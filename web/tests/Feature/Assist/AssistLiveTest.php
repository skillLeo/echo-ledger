<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Assist\AssistLiveController;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Services\Assist\AssistTransport;
use App\Services\Assist\Http\EventStream;
use App\Services\Assist\Http\LiveContext;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\Support\ApiClient;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * HTTP mode's live endpoint (1.9.0-q): one app message per POST, the answer a stream of server-sent events.
 */

beforeEach(function () {
    assistRelaySetUp();
    app(SettingsRepository::class)->set('assist.connection', AssistTransport::SHARED);
});

it('streams with the headers that keep the CDN from holding it, and ends with an end event', function () {
    [$api, $id] = httpSession($this);

    $response = $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update', 'brief' => ['title' => 'Interview']])->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')->toContain('no-transform')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no')
        ->and($response->headers->get('X-LiteSpeed-Cache-Control'))->toBe('no-cache')
        ->and(events($response->streamedContent()))->toBe([['event' => 'end', 'data' => []]]);
});

it('keeps what a context update sends, replacing only the fields present', function () {
    [$api, $id] = httpSession($this);
    $post = fn (array $m) => events($api->postJson("/api/assist/sessions/{$id}/live", $m)->assertOk()->streamedContent());

    $post(['type' => 'context_update', 'brief' => ['title' => 'A'], 'memory' => 'm1', 'facts' => [['id' => 'f1', 'text' => 'Budget 5,000 USD']]]);
    $post(['type' => 'context_update', 'memory' => '']);

    $context = app(LiveContext::class)->get(AssistSession::query()->withoutGlobalScopes()->findOrFail($id));
    expect($context['brief'])->toBe(['title' => 'A'])->and($context['memory'])->toBe('')->and($context['facts'])->toBe(['Budget 5,000 USD']);
});

it('refuses documents over the limits and keeps the old ones', function () {
    [$api, $id] = httpSession($this);
    $post = fn (array $m) => events($api->postJson("/api/assist/sessions/{$id}/live", $m)->assertOk()->streamedContent());
    $post(['type' => 'context_update', 'documents' => [['id' => 'cv', 'title' => 'CV', 'text' => 'Payments.']]]);

    $answer = $post(['type' => 'context_update', 'documents' => array_fill(0, 21, ['id' => 'x', 'title' => 'x', 'text' => 'y'])]);
    expect($answer[0]['event'])->toBe('error')->and($answer[0]['data'])->toMatchArray(['code' => 'documents_too_large', 'retry' => false, 'fatal' => false, 'seq' => 1]);
    $session = AssistSession::query()->withoutGlobalScopes()->findOrFail($id);
    expect(app(LiveContext::class)->get($session)['documents'])->toBe([['id' => 'cv', 'title' => 'CV', 'text' => 'Payments.']]);
});

it('stores the final turns of both channels once, and notes when Me spoke', function () {
    [$api, $id] = httpSession($this);
    $post = fn (array $m) => events($api->postJson("/api/assist/sessions/{$id}/live", $m)->assertOk()->streamedContent());

    $post(['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'other', 'text' => 'What is your notice period?', 'language' => 'en', 'start_ms' => 1000, 'end_ms' => 4000]);
    $post(['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'client', 'text' => 'What is your notice period?']);
    expect(AssistTurn::query()->where('assist_session_id', $id)->count())->toBe(1);

    $session = AssistSession::query()->withoutGlobalScopes()->findOrFail($id);
    expect(app(LiveContext::class)->get($session)['last_me_ms'])->toBeNull();
    $post(['type' => 'turn_partial', 'turn_id' => 'm-1', 'channel' => 'me', 'text' => 'One month']);
    expect(app(LiveContext::class)->get($session)['last_me_ms'])->toBeInt();

    expect($post(['type' => 'turn_final', 'turn_id' => '', 'channel' => 'me', 'text' => 'x'])[0]['data']['code'])->toBe('bad_message');
});

it('refuses what is not a live message, a session that is not HTTP mode, an ended one and a stranger\'s', function () {
    [$api, $id] = httpSession($this);
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'nope'])->assertStatus(422)->assertJsonPath('code', 'bad_message');
    $api->postJson('/api/assist/sessions/01JZZZZZZZZZZZZZZZZZZZZZZZ/live', ['type' => 'context_update'])->assertNotFound();

    $relay = AssistSession::query()->withoutGlobalScopes()->findOrFail($id);
    $relay->forceFill(['transport' => 'relay'])->save();
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertStatus(426)->assertJsonPath('code', 'app_update_required');

    $relay->forceFill(['transport' => 'http'])->save();
    $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertStatus(409)->assertJsonPath('code', 'session_ended');
});

it('never counts below 0 when the count ran out or was forgotten while a stream was open', function () {
    [$api, $id] = httpSession($this);
    $key = 'assist-live-open:'.$id;
    // The stream's own "end" lands after its count is gone (expired, or the cache was cleared): 0, never -1.
    Cache::forget($key);
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertOk()->streamedContent();
    expect((int) Cache::get($key))->toBe(0);

    // A stream started (count 1), then its count ran out or was forgotten before it ended: its end leaves 0, not -1.
    $count = new ReflectionMethod(AssistLiveController::class, 'openStreams');
    expect($count->invoke(null, $key, +1))->toBe(1);
    Cache::forget($key);
    expect($count->invoke(null, $key, -1))->toBe(0)->and((int) Cache::get($key))->toBe(0);
    Cache::put($key, 1, 1);
    $this->travel(5)->seconds();
    expect($count->invoke(null, $key, -1))->toBe(0);
});

it('counts open streams with a fresh lifetime on every change, never below 0', function () {
    [$api, $id] = httpSession($this);
    $key = 'assist-live-open:'.$id;

    // A stream that ends takes itself off the count; a count left by a dead worker never goes below 0.
    Cache::put($key, 0, 60);
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertOk()->streamedContent();
    expect((int) Cache::get($key))->toBe(0);

    // Each request writes the count again with the full lifetime: a count from long ago runs out instead of living on.
    Cache::put($key, 3, 5);
    $this->travel(2)->seconds();
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertOk()->streamedContent();
    expect((int) Cache::get($key))->toBe(3);
    $this->travel(10)->seconds();
    expect((int) Cache::get($key))->toBe(3);
    $this->travel(EventStream::MAX_SECONDS + 30)->seconds();
    expect(Cache::get($key))->toBeNull();
});

it('allows 10 messages a second and 4 open streams per session', function () {
    [$api, $id] = httpSession($this);
    for ($i = 0; $i < 10; $i++) {
        $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertOk()->streamedContent();
    }
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');

    $this->travel(2)->seconds();
    Cache::put('assist-live-open:'.$id, 4, 60);
    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertStatus(429)->assertJsonPath('message', 'Too many open streams for this session.');
});

it('gives the stream its own time limit and puts the previous one back afterwards', function () {
    [$api, $id] = httpSession($this);
    set_time_limit(0);

    $api->postJson("/api/assist/sessions/{$id}/live", ['type' => 'context_update'])->assertOk()->streamedContent();

    expect((int) ini_get('max_execution_time'))->toBe(0);
});
