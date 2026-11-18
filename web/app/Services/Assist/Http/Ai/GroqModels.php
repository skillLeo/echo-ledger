<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

use App\Services\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Chat Completions streaming on Groq (services/assist-relay/src/provider/openai_compat.rs is the reference): the
 * system segments become one `system` message and the rest one `user` message, always in the same order (Groq caches a
 * repeated prefix by itself). `stream_options.include_usage` asks for the counts in the last chunk (Groq may send them
 * in `x_groq.usage`; either is read). `finish_reason: content_filter` is a declined request; `[DONE]` ends the answer.
 * The key comes from the credential vault only.
 */
class GroqModels
{
    public const URL = 'https://api.groq.com/openai/v1/chat/completions';

    private const RETRY_MS = [100, 200];

    public function __construct(private readonly CredentialVault $vault) {}

    /** @return \Generator<int, ModelEvent> */
    public function stream(ModelRequest $request): \Generator
    {
        $key = $this->vault->get('groq', 'api_key');
        if ($key === null) {
            throw new ModelFailure(ModelFailure::NOT_CONFIGURED);
        }
        $deadline = new Deadline($request->firstTokenSeconds, $request->stallSeconds);
        $body = new DeadlineStream($this->open($key, $request), $deadline);
        $usage = null;
        $text = false;
        try {
            foreach (SseReader::events($body) as $event) {
                if (trim($event['data']) === '[DONE]') {
                    break;
                }
                $chunk = json_decode($event['data'], true);
                if (! is_array($chunk)) {
                    continue;
                }
                if (isset($chunk['error'])) {
                    throw new ModelFailure(ModelFailure::UNAVAILABLE);
                }
                $usage = self::usage($chunk['usage'] ?? ($chunk['x_groq']['usage'] ?? null)) ?? $usage;
                $choice = $chunk['choices'][0] ?? [];
                $word = $choice['delta']['content'] ?? null;
                if (is_string($word) && $word !== '') {
                    $text = true;
                    $deadline->wordSeen();

                    yield ModelEvent::text($word);
                }
                if (($choice['finish_reason'] ?? null) === 'content_filter') {
                    throw new ModelFailure(ModelFailure::DECLINED);
                }
            }
        } catch (ModelFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ModelFailure(str_contains(strtolower($e::class), 'timeout') ? ModelFailure::TIMEOUT : ModelFailure::UNAVAILABLE);
        }
        if ($usage !== null) {
            yield ModelEvent::usage($usage);
        } elseif (! $text) {
            // An answer without words or counts is not an answer.
            throw new ModelFailure(ModelFailure::PROTOCOL);
        }
    }

    /**
     * The request body.
     *
     * @return array<string, mixed>
     */
    public static function body(ModelRequest $request): array
    {
        $messages = [];
        if ($request->prompt->role('system') !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->prompt->role('system')];
        }
        $messages[] = ['role' => 'user', 'content' => $request->prompt->role('user')];
        $body = [
            'model' => $request->model, 'messages' => $messages, 'stream' => true,
            'stream_options' => ['include_usage' => true], 'max_completion_tokens' => $request->maxOutputTokens,
        ];

        // The model's options go to the request as they are (`reasoning_effort` for gpt-oss).
        return [...$request->options, ...$body];
    }

    /** Opens the response, trying again quickly while Groq is busy and says it will be back within a second. */
    private function open(string $key, ModelRequest $request): StreamInterface
    {
        $first = max(0.5, $request->firstTokenSeconds);
        for ($attempt = 0; ; $attempt++) {
            try {
                // `read_timeout` bounds the wait for the headers and wakes each idle read (see TimedTransport).
                $response = Http::withToken($key)->acceptJson()->timeout(60)->withOptions([
                    'stream' => true, 'connect_timeout' => min(3.0, $first), 'read_timeout' => $first,
                ])->post(self::URL, self::body($request));
            } catch (ConnectionException $e) {
                throw new ModelFailure(str_contains(strtolower($e->getMessage()), 'timed out') ? ModelFailure::TIMEOUT : ModelFailure::UNAVAILABLE);
            }
            $status = $response->status();
            if ($status >= 200 && $status < 300) {
                return $response->toPsrResponse()->getBody();
            }
            $after = $response->header('retry-after');
            if (($status === 429 || $status >= 500) && $attempt < count(self::RETRY_MS) && ($after === '' || (float) $after <= 1.0)) {
                usleep(self::RETRY_MS[$attempt] * 1000);

                continue;
            }
            throw new ModelFailure(match (true) {
                $status === 401 || $status === 403 => ModelFailure::NOT_CONFIGURED,
                $status === 429 => ModelFailure::RATE_LIMITED,
                $status >= 500 => ModelFailure::UNAVAILABLE,
                default => ModelFailure::PROTOCOL,
            });
        }
    }

    /** @return array{input: int, cached: int, written: int, output: int}|null */
    private static function usage(mixed $usage): ?array
    {
        if (! is_array($usage)) {
            return null;
        }
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $cached = min($prompt, (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0));

        return ['input' => $prompt - $cached, 'cached' => $cached, 'written' => 0, 'output' => (int) ($usage['completion_tokens'] ?? 0)];
    }
}
