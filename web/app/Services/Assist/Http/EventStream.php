<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One server-sent-events response of HTTP mode (docs/API_ASSIST.md 1.9.0-q), with the recipe measured through the
 * host's CDN: no PHP buffering or compression, a flush after every event, headers that stop proxies from holding it.
 * Each event is `event: <type>` and `data: <the relay's message as JSON>`; `seq` counts from 1 per stream.
 */
final class EventStream
{
    /** A stream lasts at most this long (the app asks again); under the host's web limit of 360 s. */
    public const MAX_SECONDS = 60;

    private int $seq = 0;

    private float $startedAt;

    private function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /** @param  Closure(self): void  $work  runs inside the response, emitting events */
    public static function respond(Closure $work): StreamedResponse
    {
        return new StreamedResponse(function () use ($work): void {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            @ini_set('implicit_flush', '1');
            @set_time_limit(self::MAX_SECONDS + 15);
            // Under the test runner the buffers are the runner's: leave them alone.
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            $stream = new self;
            $work($stream);
            $stream->send('end', []);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'X-LiteSpeed-Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Sends one relay message as an event; `type` is its name.
     *
     * @param  array<string, mixed>  $message
     */
    public function send(string $type, array $message): void
    {
        $this->seq++;
        $body = $type === 'end' ? [] : ['type' => $type, 'seq' => $this->seq, ...$message];
        echo "event: {$type}\ndata: ".json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        $this->flush();
    }

    /** A keep-alive comment: tells every proxy the stream is alive while a held question waits. */
    public function keepAlive(): void
    {
        echo ": keep-alive\n\n";
        $this->flush();
    }

    /** The relay's `error` message. */
    public function error(string $code, string $message, bool $retry = false, ?string $suggestionId = null, bool $fatal = false): void
    {
        $this->send('error', ['code' => $code, 'message' => $message, 'retry' => $retry, 'suggestion_id' => $suggestionId, 'in_reply_to' => null, 'fatal' => $fatal]);
    }

    /** True once the stream has run its allowed time. */
    public function expired(): bool
    {
        return microtime(true) - $this->startedAt >= self::MAX_SECONDS;
    }

    private function flush(): void
    {
        if (! app()->runningUnitTests()) {
            @flush();
        }
    }
}
