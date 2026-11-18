<?php

declare(strict_types=1);

use App\Services\Assist\AssistTransport;
use App\Services\Assist\Http\EventStream;
use App\Services\Assist\RelayHealth;
use App\Services\SettingsRepository;
use Tests\Feature\Assist\Support\AssistCard;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * The card's shared sequences (docs/v2/assist-card-sequences.json: relay messages in order, and the card after each) are
 * played through both transports and must give the same card after every message:
 *
 * - the relay's: every message a WebSocket frame on one connection;
 * - HTTP mode's: every message an event of the stream of the request that carries it (serialised by the server's own
 *   EventStream, `seq` counted per stream, each stream closed by `end`), the app feeding the events of every open stream
 *   into one card in arrival order, `ready` coming from the REST answer.
 *
 * Then the server itself is driven, with scripted models, through the scenarios the sequences describe, and what comes
 * out of its streams is the file's messages and cards.
 */

beforeEach(function () {
    assistRelaySetUp(healthy: false);
    app(SettingsRepository::class)->set('assist.connection', AssistTransport::SHARED);
    config(['assist.http.card_check_ms' => 0, 'assist.http.hold_max_ms' => 300, 'assist.http.hold_poll_ms' => 10]);
});

afterEach(fn () => RelayHealth::$resolveUsing = null);

/** @return list<array{name: string, steps: list<array{message: array<string, mixed>, expect: array<string, mixed>}>}> */
function assistSequences(): array
{
    $file = dirname(__DIR__, 4).'/docs/v2/assist-card-sequences.json';
    expect(is_file($file))->toBeTrue("the shared card sequences are missing: {$file}");

    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['sequences'];
}

/** @return array{name: string, steps: list<array{message: array<string, mixed>, expect: array<string, mixed>}>} */
function assistSequence(string $name): array
{
    foreach (assistSequences() as $sequence) {
        if ($sequence['name'] === $name) {
            return $sequence;
        }
    }

    throw new RuntimeException("No shared card sequence named: {$name}");
}

dataset('card sequences', fn () => array_combine(array_column(assistSequences(), 'name'), array_map(fn (array $s): array => [$s], assistSequences())));

/**
 * One HTTP stream's body: [$messages] sent through the server's own EventStream (the app's messages as events), the
 * response closed by `end`, as the server writes it.
 *
 * @param  list<array<string, mixed>>  $messages
 */
function assistSseOf(array $messages): string
{
    $response = EventStream::respond(function (EventStream $stream) use ($messages): void {
        foreach ($messages as $message) {
            $type = (string) $message['type'];
            unset($message['type'], $message['seq']);
            $stream->send($type, $message);
        }
    });
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/**
 * Which HTTP stream carries each message: a new question opens a stream, and what answers it (its words, its end, an
 * upgrade, a fallback that `replaces` it) is in that same stream. `ready` is not a stream's: the REST answer has it.
 *
 * @param  list<array<string, mixed>>  $messages
 * @return list<int|null>
 */
function assistStreamsOf(array $messages): array
{
    $of = [];
    $next = 0;
    $streams = [];
    foreach ($messages as $m) {
        $type = $m['type'];
        if ($type === 'ready') {
            $streams[] = null;
        } elseif ($type === 'suggestion_start') {
            $replaced = $m['replaces'] ?? null;
            $of[$m['suggestion_id']] = $replaced !== null && isset($of[$replaced]) ? $of[$replaced] : $next++;
            $streams[] = $of[$m['suggestion_id']];
        } elseif (isset($m['suggestion_id'], $of[$m['suggestion_id']])) {
            $streams[] = $of[$m['suggestion_id']];
        } else {
            // A message that names no suggestion (a skip without one): its own request.
            $streams[] = $next++;
        }
    }

    return $streams;
}

it('gives the same card through the relay\'s connection and through HTTP mode\'s streams, after every message', function (array $sequence) {
    $messages = array_column($sequence['steps'], 'message');
    $expected = array_column($sequence['steps'], 'expect');

    // The relay: one connection, a frame per message, `seq` counted from 1 across them.
    $state = AssistCard::empty();
    $relay = [];
    $last = 0;
    foreach ($messages as $i => $message) {
        $frame = json_decode((string) json_encode($message), true);
        expect($frame['seq'])->toBeGreaterThan($last);
        $last = $frame['seq'];
        $state = AssistCard::reduce($state, $frame);
        $relay[] = AssistCard::cardOf($state, $expected[$i]['also'] ?? null);
    }

    // HTTP mode: the streams, each serialised by the server and read back as the app reads it.
    $streamOf = assistStreamsOf($messages);
    $bodies = [];
    foreach (array_unique(array_filter($streamOf, fn ($s) => $s !== null)) as $stream) {
        $bodies[$stream] = array_values(array_filter($messages, fn ($m, $i) => $streamOf[$i] === $stream, ARRAY_FILTER_USE_BOTH));
    }
    $read = array_map(fn (array $stream): array => events(assistSseOf($stream)), $bodies);
    // Each stream counts its own `seq` from 1 and ends with `end`.
    foreach ($read as $stream => $events) {
        $wire = $events;
        $end = array_pop($wire);
        expect($end)->toBe(['event' => 'end', 'data' => []])->and(array_column(array_column($wire, 'data'), 'seq'))->toBe(range(1, count($wire)));
        expect(array_column($wire, 'event'))->toBe(array_column($bodies[$stream], 'type'));
    }
    $cursor = array_fill_keys(array_keys($read), 0);
    $state = AssistCard::empty();
    $http = [];
    foreach ($messages as $i => $message) {
        // Arrival order is the sequence's order; `ready` comes from the REST answer, the rest from a stream.
        $event = $streamOf[$i] === null ? $message : $read[$streamOf[$i]][$cursor[$streamOf[$i]]++]['data'];
        $state = AssistCard::reduce($state, $event);
        $http[] = AssistCard::cardOf($state, $expected[$i]['also'] ?? null);
    }

    foreach ($expected as $i => $card) {
        expect($relay[$i])->toBe($card, 'relay, step '.($i + 1).' ('.$messages[$i]['type'].')')
            ->and($http[$i])->toBe($relay[$i], 'HTTP mode, step '.($i + 1).' ('.$messages[$i]['type'].')');
    }
})->with('card sequences');

it('has every sequence the apps run', function () {
    expect(count(assistSequences()))->toBeGreaterThanOrEqual(16);
    foreach (assistSequences() as $sequence) {
        expect($sequence['steps'])->not->toBe([]);
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// The server's own streams, driven with scripted models
// ---------------------------------------------------------------------------------------------------------------------

/**
 * What the file's messages say, of a message from either side: its type, its suggestion by the order it appeared in, and
 * the fields a card is made of. The rest (the ids of the turns, the models, the latency) is not the card's.
 *
 * @param  list<array<string, mixed>>  $messages
 * @return list<array<string, mixed>>
 */
function assistProject(array $messages): array
{
    $ids = [];
    $name = function (?string $id) use (&$ids): ?string {
        if ($id === null) {
            return null;
        }

        return $ids[$id] ??= 's-'.(count($ids) + 1);
    };
    $out = [];
    foreach ($messages as $m) {
        // `end` closes a stream and has no body; `ready` is the REST answer's.
        if (($m['type'] ?? 'end') === 'ready' || ! isset($m['type'])) {
            continue;
        }
        $p = ['type' => $m['type'], 'suggestion_id' => $name($m['suggestion_id'] ?? null)];
        foreach (['kind', 'text', 'status'] as $field) {
            if (array_key_exists($field, $m)) {
                $p[$field] = $m[$field];
            }
        }
        if (array_key_exists('replaces', $m)) {
            $p['replaces'] = $name($m['replaces']);
        }
        $out[] = $p;
    }

    return $out;
}

/** @return list<array<string, mixed>> the file's messages of [$sequence] after its `ready`, projected */
function assistSequenceMessages(string $name): array
{
    return assistProject(array_column(assistSequence($name)['steps'], 'message'));
}

/**
 * The card after each of [$events] (the app's view of the streams, in arrival order), `ready` first as the REST answer
 * gives it, ids named s-1, s-2 in the order they appear.
 *
 * @param  list<array{event: string, data: array<string, mixed>}>  $events
 * @return list<array<string, mixed>> the card after every event that is not `end`
 */
function assistCardsOf(array $events): array
{
    $ids = [];
    $state = AssistCard::reduce(AssistCard::empty(), ['type' => 'ready', 'resumed' => false]);
    $cards = [];
    foreach ($events as $event) {
        if ($event['event'] === 'end') {
            continue;
        }
        $m = $event['data'];
        foreach (['suggestion_id', 'replaces'] as $field) {
            if (isset($m[$field])) {
                $m[$field] = $ids[$m[$field]] ??= 's-'.(count($ids) + 1);
            }
        }
        $state = AssistCard::reduce($state, $m);
        $cards[] = AssistCard::cardOf($state);
    }

    return $cards;
}

/** The card the file expects after step [$step] (1-based, `ready` being the first) of [$name], without `also`. */
function assistExpectedCard(string $name, int $step): array
{
    $card = assistSequence($name)['steps'][$step - 1]['expect'];
    unset($card['also']);

    return $card;
}

const ASSIST_Q1 = ['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'other', 'text' => 'What is your notice period?', 'start_ms' => 1000, 'end_ms' => 4000];
const ASSIST_Q2 = ['type' => 'turn_final', 'turn_id' => 'o-2', 'channel' => 'other', 'text' => 'And the start date?', 'start_ms' => 9000, 'end_ms' => 11000];

/** A stream's events so far, from inside a scripted model call: how many the request has written when something else happens. */
function assistWrittenSoFar(): int
{
    return count(events((string) ob_get_contents()));
}

it('streams what the file\'s first sequence says: a suggestion token by token, ending done', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['Yes, ', '4,200 holds.', ['usage' => [300, 0, 0, 20]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_Q1);

    $name = 'A suggestion streams token by token and ends done';
    expect(assistProject(array_column($events, 'data')))->toBe(assistSequenceMessages($name));
    $cards = assistCardsOf($events);
    expect($cards[0])->toBe(assistExpectedCard($name, 2))->and($cards[1])->toBe(assistExpectedCard($name, 3))
        ->and($cards[2])->toBe(assistExpectedCard($name, 4))->and($cards[3])->toBe(assistExpectedCard($name, 5));
});

it('streams what the file says of a draft and its upgrade: the draft stays on the card until the upgrade\'s first word', function () {
    $models = fakeAssistModels()->on('claude-haiku-5-5', ['Draft answer.', ['usage' => [300, 0, 0, 10]]])->on('claude-sonnet-5-5', ['Yes, it holds for sixty.', ['usage' => [900, 0, 0, 20]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ['type' => 'turn_final', 'turn_id' => 'o-1', 'channel' => 'other', 'text' => 'Why did you leave your last job, and what would you do differently now?', 'start_ms' => 1, 'end_ms' => 2]);

    $name = 'A replacement waits off the card until its first token; the replaced one keeps its text';
    expect(assistProject(array_column($events, 'data')))->toBe(assistSequenceMessages($name))
        ->and(array_slice(assistCardsOf($events), 0, 6))->toBe(array_map(fn (int $step): array => assistExpectedCard($name, $step), range(2, 7)))
        ->and($models->asked())->toBe(['claude-haiku-5-5', 'claude-sonnet-5-5']);
});

it('streams what the file says of an answer that needs no reply', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['S', 'KIP', ['usage' => [300, 0, 0, 2]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_Q1);

    $name = 'No reply needed stays across a resumed ready';
    expect(assistProject(array_column($events, 'data')))->toBe(array_slice(assistSequenceMessages($name), 0, 2))
        ->and(assistCardsOf($events))->toBe([assistExpectedCard($name, 2), assistExpectedCard($name, 3)]);
});

it('hands the card to the new question: the old suggestion ends superseded, and its writer says no more', function () {
    $inner = [];
    $written = 0;
    fakeAssistModels()->on('claude-haiku-5-5', [
        'I promise delivery tomorrow',
        ['do' => function () use (&$inner, &$written, &$api, &$id) {
            $written = assistWrittenSoFar();
            $inner = assistLive($api, $id, ASSIST_Q2);
        }],
        ' and more words the card must never get',
        ['usage' => [300, 0, 0, 10]],
    ])->on('claude-haiku-5-5', ['Delivery by Thursday.', ['usage' => [300, 0, 0, 10]]]);
    [$api, $id] = httpSession($this);

    $outer = assistLive($api, $id, ASSIST_Q1);
    // The app's view: the first stream up to where the second began, the second, the rest of the first.
    $events = [...array_slice($outer, 0, $written), ...$inner, ...array_slice($outer, $written)];
    $cards = assistCardsOf($events);

    expect(array_column(array_column($events, 'data'), 'type'))->toBe(['suggestion_start', 'token', 'suggestion_end', 'suggestion_start', 'token', 'suggestion_end']);
    // After the old one's end, as "A superseded end with no replacement clears the card's text"; after the new one's first
    // word, as "A superseded end hands the card to its replacement at once".
    expect($cards[2])->toBe(assistExpectedCard('A superseded end with no replacement clears the card\'s text', 4))
        ->and($cards[4])->toBe(assistExpectedCard('A superseded end hands the card to its replacement at once', 6))
        ->and(end($cards)['status'])->toBe('done');
    expect(json_encode($events))->not->toContain('the card must never get');
});

it('puts back what the card showed before when the new suggestion is cancelled', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['First answer.', ['usage' => [300, 0, 0, 10]]])->on('claude-haiku-5-5', [
        ['do' => function () use (&$inner, &$written, &$api, &$id) {
            $written = assistWrittenSoFar();
            $inner = assistLive($api, $id, ['type' => 'cancel']);
        }],
        'Second.',
        ['usage' => [300, 0, 0, 10]],
    ]);
    [$api, $id] = httpSession($this);
    $first = assistLive($api, $id, ASSIST_Q1);

    $outer = assistLive($api, $id, ASSIST_Q2);
    $events = [...$first, ...array_slice($outer, 0, $written), ...$inner, ...array_slice($outer, $written)];

    $name = 'Cancelled puts back what the card showed before';
    expect(assistProject(array_column($events, 'data')))->toBe(assistSequenceMessages($name))
        ->and(assistCardsOf($events))->toBe(array_map(fn (int $step): array => assistExpectedCard($name, $step), range(2, 6)));
});

it('replaces a suggestion whose model broke after its first words, with the card never blank', function () {
    fakeAssistModels()->on('claude-haiku-5-5', ['I promise delivery tomorrow', ['fail' => 'unavailable']])->on('openai/gpt-oss-120b', ['Delivery by Thursday.', ['usage' => [300, 0, 0, 10]]]);
    [$api, $id] = httpSession($this);

    $events = assistLive($api, $id, ASSIST_Q1);
    $cards = assistCardsOf($events);

    $name = 'A superseded end hands the card to its replacement at once';
    // start s-1, token, end s-1 superseded, start s-2 (replaces s-1), token, end s-2 done.
    expect(array_column(array_column($events, 'data'), 'type'))->toBe(['suggestion_start', 'token', 'suggestion_end', 'suggestion_start', 'token', 'suggestion_end'])
        ->and(array_slice($cards, 0, 2))->toBe([assistExpectedCard($name, 2), assistExpectedCard($name, 3)])
        ->and($cards[2])->toBe(['shown_id' => 's-1', 'text' => '', 'status' => 'superseded', 'skipped' => false])
        ->and($cards[4])->toBe(['shown_id' => 's-2', 'text' => 'Delivery by Thursday.', 'status' => 'streaming', 'skipped' => false])
        ->and($cards[5])->toBe(['shown_id' => 's-2', 'text' => 'Delivery by Thursday.', 'status' => 'done', 'skipped' => false]);
});

it('keeps the card as it was when the app drops a stream: a resumed ready clears a suggestion still writing', function () {
    // A stream cut after the first words (the app read no `suggestion_end`): the app treats it as the file says.
    fakeAssistModels()->on('claude-haiku-5-5', ['Half an ans', ['usage' => [300, 0, 0, 10]]]);
    [$api, $id] = httpSession($this);
    $events = assistLive($api, $id, ASSIST_Q1);
    $cut = array_slice($events, 0, 2);

    $name = 'A resumed ready clears a suggestion still streaming';
    $state = AssistCard::reduce(AssistCard::empty(), ['type' => 'ready', 'resumed' => false]);
    foreach ($cut as $e) {
        $state = AssistCard::reduce($state, $e['data']);
    }
    expect(AssistCard::cardOf($state)['status'])->toBe('streaming');
    $state = AssistCard::reduce($state, ['type' => 'ready', 'resumed' => true]);

    expect(AssistCard::cardOf($state))->toBe(assistExpectedCard($name, 7));
});

// ---------------------------------------------------------------------------------------------------------------------
// Automatic selection, end to end
// ---------------------------------------------------------------------------------------------------------------------

/** Starts a session knowing both transports, as an app of this version does; returns its API client, its id and the answer. */
function assistStartBoth(object $test): array
{
    $api = (fn () => $this->api(Fx::token(assistPerson('pro'))))->call($test);
    $start = $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'balanced', 'transports' => ['relay', 'http']])->assertCreated();

    return [$api, (string) $start->json('session.id'), $start->json()];
}

it('runs a session over this server under Shared hosting (emergency) while the relay is down, and keeps it there when the relay comes back', function () {
    // Since the owner's rule (2026-11-18) only Shared hosting, chosen by hand, runs on this server while a relay is set up.
    app(SettingsRepository::class)->set('assist.connection', AssistTransport::SHARED);
    fakeAssistModels();
    app(RelayHealth::class)->record(['ok' => false]);

    [$api, $id, $start] = assistStartBoth($this);
    expect($start['transport'])->toBe('http')->and($start['relay'])->toBeNull()->and($start['http']['live_url'])->toEndWith("/api/assist/sessions/{$id}/live");
    expect(assistNames(assistLive($api, $id, ASSIST_Q1)))->toBe(['suggestion_start', 'token', 'token', 'suggestion_end', 'end']);

    // The relay answers again: this session stays on the transport it started on, for its whole life.
    $this->travel(61)->seconds();
    app(RelayHealth::class)->record(['ok' => true]);
    expect(assistNames(assistLive($api, $id, ASSIST_Q2)))->toContain('suggestion_start');
    expect($api->postJson("/api/assist/sessions/{$id}/token")->assertOk()->json('transport'))->toBe('http');
});

it('runs a session on the relay under Automatic while it answers, and this server\'s live endpoint refuses that session', function () {
    app(SettingsRepository::class)->set('assist.connection', AssistTransport::AUTOMATIC);
    fakeAssistModels();
    app(RelayHealth::class)->record(['ok' => true]);

    [$api, $id, $start] = assistStartBoth($this);

    expect($start['transport'])->toBe('relay')->and($start['http'])->toBeNull()->and($start['relay']['url'])->toStartWith('wss://');
    $api->postJson("/api/assist/sessions/{$id}/live", ASSIST_Q1)->assertStatus(426)->assertJsonPath('code', 'app_update_required');
});

it('refuses a session under Automatic and Dedicated relay while the relay is down, never moving it to this server', function () {
    fakeAssistModels();
    app(RelayHealth::class)->record(['ok' => false]);

    foreach ([AssistTransport::AUTOMATIC, AssistTransport::RELAY] as $choice) {
        app(SettingsRepository::class)->set('assist.connection', $choice);
        $api = (fn () => $this->api(Fx::token(assistPerson('pro'))))->call($this);
        $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'balanced', 'transports' => ['relay', 'http']])
            ->assertStatus(503)->assertJsonPath('code', 'relay_unavailable');
    }
});
