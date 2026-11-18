<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

use Psr\Http\Message\StreamInterface;

/**
 * Cuts a streamed response body into server-sent events: `event:` and `data:` lines, an empty line ends an event, `:`
 * lines are comments. Reads one byte and then only what is already buffered, as PHP's HTTP wrapper holds a read of N
 * bytes from a chunked response until all N arrive.
 */
final class SseReader
{
    /**
     * @return \Generator<int, array{event: string|null, data: string}>
     */
    public static function events(StreamInterface $body): \Generator
    {
        $http = $body->getMetadata('wrapper_type') === 'http';
        $buffer = '';
        $event = null;
        $data = [];
        while (! $body->eof()) {
            $chunk = $body->read($http ? 1 : 8192);
            $unread = $http ? $body->getMetadata('unread_bytes') : 0;
            if (is_int($unread) && $unread > 0) {
                $chunk .= $body->read($unread);
            }
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);
                if ($line === '') {
                    if ($data !== [] || $event !== null) {
                        yield ['event' => $event, 'data' => implode("\n", $data)];
                    }
                    $event = null;
                    $data = [];

                    continue;
                }
                if ($line[0] === ':') {
                    continue;
                }
                [$field, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, ''];
                $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;
                if ($field === 'event') {
                    $event = $value;
                } elseif ($field === 'data') {
                    $data[] = $value;
                }
            }
        }
        if ($data !== [] || $event !== null) {
            yield ['event' => $event, 'data' => implode("\n", $data)];
        }
    }
}
