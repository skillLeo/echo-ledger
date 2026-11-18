<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The transport the Anthropic SDK sends one streamed model call through (the SDK enforces no timeout of its own: it
 * leaves that to the transport). The body is read with a short idle timeout and checked against the call's
 * [Deadline] (DeadlineStream); the connection and the wait for the response headers are bounded by the first-token
 * timeout (measured against PHP's HTTP stream: a model that sends its headers late, its first word late, or stalls
 * mid-answer each fails within a read timeout of its limit).
 */
final class TimedTransport implements ClientInterface
{
    public function __construct(private readonly Deadline $deadline, private readonly ?Client $http = null) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $first = max(0.5, $this->deadline->firstSeconds);
        // `read_timeout` is the transport's idle timeout: it bounds the wait for the response headers and wakes each
        // read that finds nothing, so the Deadline is looked at. No total `timeout`: the stream's own time is the app's.
        $response = ($this->http ?? new Client)->send($request, [
            'stream' => true, 'http_errors' => false, 'allow_redirects' => false, 'connect_timeout' => min(3.0, $first), 'read_timeout' => $first,
        ]);

        return $response->withBody(new DeadlineStream($response->getBody(), $this->deadline));
    }
}
