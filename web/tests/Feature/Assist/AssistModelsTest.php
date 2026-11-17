<?php

declare(strict_types=1);

use App\Services\Assist\Http\Ai\AnthropicModels;
use App\Services\Assist\Http\Ai\Deadline;
use App\Services\Assist\Http\Ai\DeadlineStream;
use App\Services\Assist\Http\Ai\GroqModels;
use App\Services\Assist\Http\Ai\ModelEvent;
use App\Services\Assist\Http\Ai\ModelFailure;
use App\Services\Assist\Http\Ai\ModelRequest;
use App\Services\Assist\Http\Origin;
use App\Services\Assist\Http\Prompt\PromptBuilder;
use App\Services\Assist\Http\Prompt\Transcript;
use App\Services\CredentialVault;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/*
 * The two providers behind HTTP mode, with nothing real behind them: Anthropic through the SDK over a scripted PSR-18
 * transport, Groq through the HTTP client faked. What each sends, how each answer is read, and how each failure maps.
 */

const ASSIST_ANTHROPIC_KEY = 'sk-ant-test-0123456789-never-logged';
const ASSIST_GROQ_KEY = 'gsk-test-0123456789-never-logged';

/** A model request for [$model] with a small real prompt. */
function assistModelRequest(string $model, float $first = 2.5): ModelRequest
{
    $transcript = Transcript::fromConfig();
    $transcript->push(['id' => 'o-1', 'channel' => 'other', 'text' => 'What is your notice period?', 'start_ms' => 1000]);
    $prompt = PromptBuilder::shipped()->build(['brief' => ['title' => 'Backend role']], $transcript, Origin::answer('o-1', 'What is your notice period?'), 'full');
    $spec = config("assist.models.{$model}");

    return new ModelRequest($model, $spec['provider'], $prompt, 300, $spec['options'] ?? [], $first, 10.0);
}

/** @param  list<array<string, mixed>>  $events  Anthropic's stream events */
function assistAnthropicStream(array $events): string
{
    return implode('', array_map(fn (array $e): string => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
}

/** @return list<array<string, mixed>> a complete Anthropic answer of [$words], with the counts in its first and last events */
function assistAnthropicAnswer(array $words, string $stop = 'end_turn'): array
{
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 4000, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'ping'],
    ];
    foreach ($words as $word) {
        $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $word]];
    }
    $events[] = ['type' => 'content_block_stop', 'index' => 0];
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => 31]];
    $events[] = ['type' => 'message_stop'];

    return $events;
}

/** A PSR-18 transport that answers from a script of responses and remembers the requests: the SDK's only way out in the tests. */
function assistTransport(array $responses): object
{
    return new class($responses) implements ClientInterface
    {
        /** @var list<RequestInterface> */
        public array $sent = [];

        public function __construct(private array $responses) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->sent[] = $request;
            $next = array_shift($this->responses) ?? new Response(500, [], '{}');

            return $next instanceof Closure ? $next() : $next;
        }
    };
}

/** @return list<ModelEvent> every event of a model's stream */
function assistDrain(Generator $stream): array
{
    return iterator_to_array($stream, false);
}

function assistSse(string $body): Response
{
    return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
}

function assistAnthropicError(int $status, string $type, array $headers = []): Response
{
    return new Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'x']]));
}

beforeEach(fn () => app(CredentialVault::class)->put('anthropic', 'api_key', ASSIST_ANTHROPIC_KEY));

it('streams Anthropic\'s answer through the SDK: the words in order, then the counts', function () {
    $transport = assistTransport([assistSse(assistAnthropicStream(assistAnthropicAnswer(['Yes, ', 'one month.'])))]);

    $events = assistDrain((new AnthropicModels(app(CredentialVault::class), $transport))->stream(assistModelRequest('claude-haiku-5-5')));

    expect(array_map(fn (ModelEvent $e): ?string => $e->text, $events))->toBe(['Yes, ', 'one month.', null])
        // The first event's counts, with the final output count from message_delta.
        ->and($events[2]->usage)->toBe(['input' => 300, 'cached' => 4000, 'written' => 0, 'output' => 31]);
});

it('sends the prompt as system blocks and one user message with cache breakpoints, the model\'s options, and no sampling parameters', function () {
    $transport = assistTransport([assistSse(assistAnthropicStream(assistAnthropicAnswer(['Yes.'])))]);
    $request = assistModelRequest('claude-haiku-5-5');
    $request = new ModelRequest($request->model, $request->provider, $request->prompt, 300, [...$request->options, 'temperature' => 0.2, 'top_p' => 0.9, 'top_k' => 5], 2.5, 10.0);

    assistDrain((new AnthropicModels(app(CredentialVault::class), $transport))->stream($request));

    $sent = $transport->sent[0];
    $body = json_decode((string) $sent->getBody(), true);
    expect((string) $sent->getUri())->toEndWith('/v1/messages')
        ->and($sent->getHeaderLine('Authorization'))->toBe('Bearer '.ASSIST_ANTHROPIC_KEY)->and($sent->getHeaderLine('anthropic-version'))->toBe('2026-10-21')
        ->and($body)->toMatchArray(['model' => 'claude-haiku-5-5', 'max_tokens' => 300, 'stream' => true])
        ->and($body['output_config'])->toBe(['effort' => 'low'])->and($body['thinking'])->toBe(['type' => 'disabled'])
        ->and($body)->not->toHaveKeys(['temperature', 'top_p', 'top_k']);
    $system = $body['system'];
    expect($system[0]['cache_control'])->toBe(['type' => 'ephemeral'])->and($system[0]['text'])->toContain('Live Assist: system rules')
        ->and($body['messages'])->toHaveCount(1)->and($body['messages'][0]['role'])->toBe('user');
    $blocks = array_merge($system, $body['messages'][0]['content']);
    expect(count(array_filter($blocks, fn (array $b): bool => isset($b['cache_control']))))->toBeBetween(1, 3)
        ->and(end($blocks)['text'])->toEndWith("What is your notice period?\n");
    // Sonnet turns thinking off with between_tools; Opus has no thinking option at all.
    expect(AnthropicModels::params(assistModelRequest('claude-sonnet-5-5'))['thinking'])->toBe(['type' => 'between_tools'])
        ->and(AnthropicModels::params(assistModelRequest('claude-opus-5-5')))->not->toHaveKey('thinking');
});

it('fails the attempt at a refusal, found one event before the end', function () {
    $transport = assistTransport([assistSse(assistAnthropicStream(assistAnthropicAnswer(['I will ', 'tell you '], 'refusal')))]);
    $stream = (new AnthropicModels(app(CredentialVault::class), $transport))->stream(assistModelRequest('claude-sonnet-5-5'));

    $seen = [];
    try {
        foreach ($stream as $event) {
            $seen[] = $event->text;
        }
        $this->fail('a refusal must fail the attempt');
    } catch (ModelFailure $e) {
        expect($e->reason)->toBe(ModelFailure::DECLINED)->and($e->code())->toBe('model_declined')->and($e->retryable())->toBeFalse()->and($seen)->toBe(['I will ', 'tell you ']);
    }
});

it('maps the provider\'s failures to what the app is told, and never puts the key in one', function (int $status, string $type, string $reason, string $code) {
    $transport = assistTransport([assistAnthropicError($status, $type), assistAnthropicError($status, $type), assistAnthropicError($status, $type)]);

    try {
        assistDrain((new AnthropicModels(app(CredentialVault::class), $transport))->stream(assistModelRequest('claude-haiku-5-5')));
        $this->fail('the call must fail');
    } catch (ModelFailure $e) {
        expect($e->reason)->toBe($reason)->and($e->code())->toBe($code)
            ->and($e->getMessage().$e->plain().$e->getTraceAsString().get_debug_type($e->getPrevious()))->not->toContain(ASSIST_ANTHROPIC_KEY)->and($e->getPrevious())->toBeNull();
    }
})->with([
    'a key the provider refuses' => [401, 'authentication_error', ModelFailure::NOT_CONFIGURED, 'provider_not_configured'],
    'no permission' => [403, 'permission_error', ModelFailure::NOT_CONFIGURED, 'provider_not_configured'],
    'busy' => [529, 'overloaded_error', ModelFailure::UNAVAILABLE, 'provider_unavailable'],
    'a server error' => [500, 'api_error', ModelFailure::UNAVAILABLE, 'provider_unavailable'],
    'rate limited' => [429, 'rate_limit_error', ModelFailure::RATE_LIMITED, 'provider_unavailable'],
    'a bad request' => [400, 'invalid_request_error', ModelFailure::PROTOCOL, 'provider_unavailable'],
]);

it('tries a busy provider again twice, quickly, and uses the answer that comes', function () {
    $transport = assistTransport([assistAnthropicError(529, 'overloaded_error'), assistAnthropicError(429, 'rate_limit_error', ['retry-after' => '0']), assistSse(assistAnthropicStream(assistAnthropicAnswer(['Yes.'])))]);
    $started = microtime(true);

    $events = assistDrain((new AnthropicModels(app(CredentialVault::class), $transport))->stream(assistModelRequest('claude-haiku-5-5')));

    expect($transport->sent)->toHaveCount(3)->and($events[0]->text)->toBe('Yes.')->and(microtime(true) - $started)->toBeLessThan(1.0);
});

it('does not try again when the provider asks for more than a second, or when it refuses the key', function () {
    $transport = assistTransport([assistAnthropicError(429, 'rate_limit_error', ['retry-after' => '30']), assistSse('')]);
    try {
        assistDrain((new AnthropicModels(app(CredentialVault::class), $transport))->stream(assistModelRequest('claude-haiku-5-5')));
    } catch (ModelFailure $e) {
        expect($e->reason)->toBe(ModelFailure::RATE_LIMITED);
    }
    expect($transport->sent)->toHaveCount(1);
    $transport = assistTransport([assistAnthropicError(401, 'authentication_error'), assistSse('')]);
    try {
        assistDrain((new AnthropicModels(app(CredentialVault::class), $transport))->stream(assistModelRequest('claude-haiku-5-5')));
    } catch (ModelFailure) {
    }
    expect($transport->sent)->toHaveCount(1);
});

it('fails an error event in the middle of the stream, after the words that came', function () {
    $answer = assistAnthropicAnswer(['Yes, ']);
    array_splice($answer, 4, 0, [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'x']]]);
    $stream = (new AnthropicModels(app(CredentialVault::class), assistTransport([assistSse(assistAnthropicStream($answer))])))->stream(assistModelRequest('claude-haiku-5-5'));

    expect($stream->current()->text)->toBe('Yes, ');
    expect(fn () => $stream->next())->toThrow(ModelFailure::class);
});

it('is not configured without a key in the vault, and sends nothing', function () {
    app(CredentialVault::class)->forget('anthropic', 'api_key');
    $transport = assistTransport([]);

    expect(fn () => assistDrain((new AnthropicModels(app(CredentialVault::class), $transport))->stream(assistModelRequest('claude-haiku-5-5'))))
        ->toThrow(fn (ModelFailure $e) => $e->reason === ModelFailure::NOT_CONFIGURED);
    expect($transport->sent)->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// Groq
// ---------------------------------------------------------------------------------------------------------------------

/** @param  list<array<string, mixed>|string>  $chunks */
function assistGroqStream(array $chunks): string
{
    return implode('', array_map(fn ($c): string => 'data: '.(is_string($c) ? $c : json_encode($c))."\n\n", $chunks));
}

function assistGroqWord(string $word): array
{
    return ['choices' => [['index' => 0, 'delta' => ['content' => $word], 'finish_reason' => null]]];
}

it('streams Groq\'s answer: the words, then the counts from the last chunk, the cached share apart', function () {
    app(CredentialVault::class)->put('groq', 'api_key', ASSIST_GROQ_KEY);
    Http::fake(['api.groq.com/*' => Http::response(assistGroqStream([assistGroqWord('One '), assistGroqWord('month.'),
        ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 300]]], '[DONE]']), 200, ['Content-Type' => 'text/event-stream'])]);

    $events = assistDrain((new GroqModels(app(CredentialVault::class)))->stream(assistModelRequest('openai/gpt-oss-120b')));

    expect(array_map(fn (ModelEvent $e): ?string => $e->text, $events))->toBe(['One ', 'month.', null])
        ->and($events[2]->usage)->toBe(['input' => 200, 'cached' => 300, 'written' => 0, 'output' => 20]);
    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === GroqModels::URL && $request->hasHeader('Authorization', 'Bearer '.ASSIST_GROQ_KEY)
            && $body['model'] === 'openai/gpt-oss-120b' && $body['stream'] === true && $body['stream_options'] === ['include_usage' => true]
            && $body['max_completion_tokens'] === 300 && $body['reasoning_effort'] === 'low'
            && $body['messages'][0]['role'] === 'system' && str_contains($body['messages'][0]['content'], 'Live Assist: system rules')
            && $body['messages'][1]['role'] === 'user' && str_ends_with($body['messages'][1]['content'], "What is your notice period?\n");
    });
});

it('reads Groq\'s counts from x_groq when the usual place has none', function () {
    app(CredentialVault::class)->put('groq', 'api_key', ASSIST_GROQ_KEY);
    Http::fake(['api.groq.com/*' => Http::response(assistGroqStream([assistGroqWord('Yes.'), ['choices' => [], 'x_groq' => ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5]]], '[DONE]']))]);

    $events = assistDrain((new GroqModels(app(CredentialVault::class)))->stream(assistModelRequest('openai/gpt-oss-120b')));

    expect($events[1]->usage)->toBe(['input' => 100, 'cached' => 0, 'written' => 0, 'output' => 5]);
});

it('fails Groq\'s content filter as a refusal', function () {
    app(CredentialVault::class)->put('groq', 'api_key', ASSIST_GROQ_KEY);
    Http::fake(['api.groq.com/*' => Http::response(assistGroqStream([assistGroqWord('I '), ['choices' => [['delta' => [], 'finish_reason' => 'content_filter']]]]))]);

    expect(fn () => assistDrain((new GroqModels(app(CredentialVault::class)))->stream(assistModelRequest('openai/gpt-oss-120b'))))
        ->toThrow(fn (ModelFailure $e) => $e->reason === ModelFailure::DECLINED);
});

it('maps Groq\'s failures to what the app is told, and never puts the key in one', function (int $status, string $reason) {
    app(CredentialVault::class)->put('groq', 'api_key', ASSIST_GROQ_KEY);
    Http::fake(['api.groq.com/*' => Http::response('{"error":{"message":"x"}}', $status)]);

    try {
        assistDrain((new GroqModels(app(CredentialVault::class)))->stream(assistModelRequest('openai/gpt-oss-120b')));
        $this->fail("{$status} must fail");
    } catch (ModelFailure $e) {
        expect($e->reason)->toBe($reason)->and($e->getMessage().$e->getTraceAsString())->not->toContain(ASSIST_GROQ_KEY)->and($e->getPrevious())->toBeNull();
    }
})->with([[401, ModelFailure::NOT_CONFIGURED], [429, ModelFailure::RATE_LIMITED], [503, ModelFailure::UNAVAILABLE], [400, ModelFailure::PROTOCOL]]);

it('tries Groq again twice when it is busy, then fails', function () {
    app(CredentialVault::class)->put('groq', 'api_key', ASSIST_GROQ_KEY);
    Http::fake(['api.groq.com/*' => Http::sequence()->push('{}', 429)->push('{}', 503)->push(assistGroqStream([assistGroqWord('Yes.'), ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1]], '[DONE]']), 200)]);

    $events = assistDrain((new GroqModels(app(CredentialVault::class)))->stream(assistModelRequest('openai/gpt-oss-120b')));

    expect($events[0]->text)->toBe('Yes.');
    Http::assertSentCount(3);
});

it('is not configured without a Groq key, and sends nothing', function () {
    Http::fake();

    expect(fn () => assistDrain((new GroqModels(app(CredentialVault::class)))->stream(assistModelRequest('openai/gpt-oss-120b'))))
        ->toThrow(fn (ModelFailure $e) => $e->reason === ModelFailure::NOT_CONFIGURED);
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------------------------------
// Timeouts
// ---------------------------------------------------------------------------------------------------------------------

/** A body that is silent: every read comes back empty and it never ends, as a model that has not started answering. */
function assistSilentBody(): FnStream
{
    return new FnStream(['read' => fn (): string => '', 'eof' => fn (): bool => false, 'isReadable' => fn (): bool => true, 'getMetadata' => fn () => null]);
}

it('fails a model that gives no first word within its first-token timeout, and only then', function () {
    $deadline = new Deadline(0.12, 5.0);
    $body = new DeadlineStream(assistSilentBody(), $deadline);
    $started = microtime(true);

    expect(fn () => $body->read(1))->toThrow(fn (ModelFailure $e) => $e->reason === ModelFailure::TIMEOUT && $e->code() === 'provider_timeout' && $e->retryable());
    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.1)->and(microtime(true) - $started)->toBeLessThan(1.0);
});

it('lets a model that has begun answering stall for the longer stall timeout, and bytes that carry no word do not reset the first-token one', function () {
    // Bytes keep arriving (a ping, the message start) but no word does: the first-token timeout still runs out.
    $deadline = new Deadline(0.1, 0.4);
    $chatty = new FnStream(['read' => function (): string {
        usleep(20_000);

        return 'x';
    }, 'eof' => fn (): bool => false, 'getMetadata' => fn () => null]);
    $body = new DeadlineStream($chatty, $deadline);
    $got = '';
    expect(function () use ($body, &$got) {
        while (true) {
            $got .= $body->read(1);
        }
    })->toThrow(ModelFailure::class);
    expect($got)->not->toBe('');

    // After the first word, the longer stall timeout counts from the last bytes.
    $deadline = new Deadline(0.1, 0.4);
    $deadline->wordSeen();
    $started = microtime(true);
    expect(fn () => (new DeadlineStream(assistSilentBody(), $deadline))->read(1))->toThrow(ModelFailure::class);
    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.4);
});

it('returns what a body gives, and ends with it', function () {
    $deadline = new Deadline(1.0, 1.0);
    $ended = false;
    $body = new DeadlineStream(new FnStream(['read' => function () use (&$ended): string {
        $ended = true;

        return 'data';
    }, 'eof' => function () use (&$ended): bool {
        return $ended;
    }, 'getMetadata' => fn () => null]), $deadline);

    expect($body->read(10))->toBe('data')->and($body->eof())->toBeTrue();
});
