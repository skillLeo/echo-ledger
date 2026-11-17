<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A response body that fails with a timeout instead of waiting for ever. Every read, empty (the transport's idle timeout
 * ran out) or with bytes, is checked against the call's [Deadline]: PHP's streams give no other way to tell "the model
 * is slow" from "the model is done" while a read blocks.
 */
final class DeadlineStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private StreamInterface $stream, private readonly Deadline $deadline) {}

    public function read(int $length): string
    {
        while (true) {
            try {
                $data = $this->stream->read($length);
            } catch (\RuntimeException $e) {
                // Guzzle's own read timeout (psr7 2.9 and later throws on an idle read): the same as an empty read.
                if (! str_contains(strtolower($e::class.' '.$e->getMessage()), 'timed out') && ! str_contains(strtolower($e::class), 'timeout')) {
                    throw $e;
                }
                $data = '';
            }
            if ($data !== '') {
                // Bytes that arrive too late are too late: a model that took longer than its first-token timeout to
                // send a word (a ping or the message start does not count) has failed, whatever comes next.
                if ($this->deadline->exceeded()) {
                    throw new ModelFailure(ModelFailure::TIMEOUT);
                }
                $this->deadline->data();

                return $data;
            }
            if ($this->stream->eof()) {
                return '';
            }
            if ($this->deadline->exceeded()) {
                throw new ModelFailure(ModelFailure::TIMEOUT);
            }
        }
    }
}
