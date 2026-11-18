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

    private bool $aborted = false;

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
            // The stream's own limit, and the previous one back afterwards: a long-lived process (a test worker, an
            // Octane worker) must not keep a 75-second limit for everything it runs next.
            $previous = (int) ini_get('max_execution_time');
            @set_time_limit(self::MAX_SECONDS + 15);
            // The work (a model call that is billed) ends and is settled even when the app has gone: PHP would otherwise
            // stop the script at the next write, with the call's reservation still open. `aborted()` says when to stop.
            ignore_user_abort(true);
            // Under the test runner the buffers are the runner's: leave them alone.
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            try {
                $stream = new self;
                $work($stream);
                $stream->send('end', []);
            } finally {
                @set_time_limit($previous);
            }
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

    /**
     * True once the stream has run its allowed time.
     *
     * @phpstan-impure
     */
    public function expired(): bool
    {
        return microtime(true) - $this->startedAt >= self::MAX_SECONDS;
    }

    /**
     * True once the app has closed the connection: nobody is reading, so the work stops.
     *
     * @phpstan-impure
     */
    public function aborted(): bool
    {
        return $this->aborted || ($this->aborted = connection_aborted() === 1);
    }

    /** Seconds since the stream started. */
    public function elapsed(): float
    {
        return microtime(true) - $this->startedAt;
    }

    private function flush(): void
    {
        if (! app()->runningUnitTests()) {
            @flush();
            // The app going away shows on a write, not before.
            $this->aborted = $this->aborted || connection_aborted() === 1;
        }
    }
}
