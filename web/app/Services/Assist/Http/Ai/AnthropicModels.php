<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

use Anthropic\Client;
use Anthropic\Core\Contracts\BaseStream;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\ErrorType;
use App\Services\Assist\Http\Prompt\Prompt;
use App\Services\CredentialVault;
use Psr\Http\Client\ClientInterface;

/**
 * Anthropic's Messages API, streamed, through the SDK (services/assist-relay/src/provider/anthropic.rs is the
 * reference). The system segments go in `system`, the rest in one user message, block by block, with a cache
 * breakpoint after each segment the prompt marks (at most 3). The key comes from the credential vault and goes nowhere
 * else: never logged, never in an error.
 *
 * API facts relied on: `output_config.effort` is set explicitly for every model; Haiku takes `thinking: disabled`,
 * Sonnet `between_tools` (no other field), Opus no thinking option; no request carries temperature, top_p or top_k;
 * a `refusal` stop reason is a failed attempt (Declined), found one event before the end of the stream.
 */
class AnthropicModels
{
    /** The pause before each quick try again of a busy provider (429, 5xx, 529), and only when its retry-after fits. */
    private const RETRY_MS = [100, 200];

    /** @param  ClientInterface|null  $transport  replaces the real transport (tests send no request) */
    public function __construct(private readonly CredentialVault $vault, private readonly ?ClientInterface $transport = null) {}

    /** @return \Generator<int, ModelEvent> */
    public function stream(ModelRequest $request): \Generator
    {
        $key = $this->vault->get('anthropic', 'api_key');
        if ($key === null) {
            throw new ModelFailure(ModelFailure::NOT_CONFIGURED);
        }
        $deadline = new Deadline($request->firstTokenSeconds, $request->stallSeconds);
        $stream = $this->open($this->client($key, $this->transport ?? new TimedTransport($deadline)), $request);
        // Closes the response once this generator ends or is destroyed. Not a `finally`: PHP 8.3.30+ and 8.4.17+ can
        // crash when they destroy a suspended generator that has one.
        $closer = new class($stream)
        {
            public function __construct(private readonly object $stream) {}

            public function __destruct()
            {
                try {
                    $this->stream->close();
                } catch (\Throwable) {
                    // Already closed.
                }
            }
        };
        $usage = ['input' => null, 'cached' => null, 'written' => null, 'output' => null];
        try {
            foreach ($stream as $event) {
                switch ($event->type) {
                    case 'message_start':
                        $usage = self::merge($usage, $event->message->usage);
                        break;
                    case 'content_block_delta':
                        if ($event->delta->type === 'text_delta' && $event->delta->text !== '') {
                            $deadline->wordSeen();

                            yield ModelEvent::text($event->delta->text);
                        }
                        break;
                    case 'message_delta':
                        $usage = self::merge($usage, $event->usage);
                        // The refusal is known here, one event before the end: fail the attempt now.
                        if ($event->delta->stopReason === 'refusal') {
                            throw new ModelFailure(ModelFailure::DECLINED);
                        }
                        break;
                    case 'message_stop':
                        yield ModelEvent::usage(array_map(fn (?int $n): int => $n ?? 0, $usage));

                        return;
                }
            }
        } catch (ModelFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
        unset($closer);
    }

    /**
     * What the SDK's create-stream call takes: the prompt cut into `system` and one user message.
     *
     * @return array<string, mixed>
     */
    public static function params(ModelRequest $request): array
    {
        $system = self::blocks($request->prompt, 'system');
        $user = self::blocks($request->prompt, 'user');
        $params = ['maxTokens' => $request->maxOutputTokens, 'messages' => [['role' => 'user', 'content' => $user]], 'model' => $request->model];
        if ($system !== []) {
            $params['system'] = $system;
        }
        // The same options for every request, so each hits one cache entry. Sampling parameters are a 400 on every
        // model from anthropic-version 2026-10-21 and are never sent, whatever the catalog says.
        if (is_string($request->options['effort'] ?? null)) {
            $params['outputConfig'] = ['effort' => $request->options['effort']];
        }
        if (is_string($request->options['thinking'] ?? null)) {
            $params['thinking'] = ['type' => $request->options['thinking']];
        }

        return $params;
    }

    /**
     * The blocks of one role's segments. Empty segments are left out (the API refuses an empty text block); a
     * breakpoint on one moves to the block before it.
     *
     * @return list<array<string, mixed>>
     */
    private static function blocks(Prompt $prompt, string $role): array
    {
        $blocks = [];
        foreach ($prompt->segments as $segment) {
            if ($segment['role'] !== $role) {
                continue;
            }
            if ($segment['text'] === '') {
                if ($segment['cache'] && $blocks !== []) {
                    $blocks[array_key_last($blocks)]['cacheControl'] = ['type' => 'ephemeral'];
                }

                continue;
            }
            $block = ['type' => 'text', 'text' => $segment['text']];
            if ($segment['cache']) {
                $block['cacheControl'] = ['type' => 'ephemeral'];
            }
            $blocks[] = $block;
        }

        return $blocks;
    }

    protected function client(string $key, ClientInterface $transport): Client
    {
        return new Client(apiKey: $key, requestOptions: ['transporter' => $transport, 'streamingTransporter' => $transport, 'maxRetries' => 0]);
    }

    /**
     * Opens the stream, trying again quickly while the provider is busy and says it will be back within a second.
     *
     * @return BaseStream<mixed>
     */
    private function open(Client $client, ModelRequest $request): BaseStream
    {
        $params = self::params($request);
        for ($attempt = 0; ; $attempt++) {
            try {
                return $client->beta->messages->createStream(
                    maxTokens: $params['maxTokens'], messages: $params['messages'], model: $params['model'], outputConfig: $params['outputConfig'] ?? null,
                    system: $params['system'] ?? null, thinking: $params['thinking'] ?? null, requestOptions: ['maxRetries' => 0],
                );
            } catch (APIStatusException $e) {
                $after = $e->response?->getHeaderLine('retry-after');
                $busy = $e->status === 429 || ($e->status ?? 0) >= 500;
                if ($busy && $attempt < count(self::RETRY_MS) && ($after === null || $after === '' || (float) $after <= 1.0)) {
                    usleep(self::RETRY_MS[$attempt] * 1000);

                    continue;
                }
                throw self::failure($e);
            } catch (\Throwable $e) {
                throw self::failure($e);
            }
        }
    }

    private static function failure(\Throwable $e): ModelFailure
    {
        if ($e instanceof ModelFailure) {
            return $e;
        }
        if ($e instanceof APIStatusException) {
            $status = $e->status ?? 0;
            $reason = match (true) {
                $status === 401 || $status === 403 || in_array($e->type, [ErrorType::AUTHENTICATION_ERROR, ErrorType::PERMISSION_ERROR, ErrorType::BILLING_ERROR], true) => ModelFailure::NOT_CONFIGURED,
                $status === 429 || $e->type === ErrorType::RATE_LIMIT_ERROR => ModelFailure::RATE_LIMITED,
                $status >= 500 || in_array($e->type, [ErrorType::OVERLOADED_ERROR, ErrorType::API_ERROR, ErrorType::TIMEOUT_ERROR], true) => ModelFailure::UNAVAILABLE,
                default => ModelFailure::PROTOCOL,
            };

            return new ModelFailure($reason);
        }
        // A dropped connection, or anything else the SDK or the transport throws: the provider may have the request.
        // One that ran out of time, anywhere in the chain of causes, is a timeout.
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof APITimeoutException || preg_match('/time[ds]? ?out/i', $cause::class.' '.$cause->getMessage()) === 1) {
                return new ModelFailure(ModelFailure::TIMEOUT);
            }
        }

        return new ModelFailure(ModelFailure::UNAVAILABLE);
    }

    /**
     * Fields a later event sends replace the earlier ones (`message_delta` carries the final output count).
     *
     * @param  array{input: int|null, cached: int|null, written: int|null, output: int|null}  $usage
     * @return array{input: int|null, cached: int|null, written: int|null, output: int|null}
     */
    private static function merge(array $usage, object $later): array
    {
        return [
            'input' => $later->inputTokens ?? $usage['input'],
            'cached' => $later->cacheReadInputTokens ?? $usage['cached'],
            'written' => $later->cacheCreationInputTokens ?? $usage['written'],
            'output' => $later->outputTokens ?? $usage['output'],
        ];
    }
}
