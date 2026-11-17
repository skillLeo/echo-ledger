<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use App\Models\AssistTurn;

/**
 * One app message of HTTP mode (the relay's client → relay messages, docs/API_ASSIST.md), answered on its event
 * stream. A message with nothing to say (a `me` turn, `context_update`) emits nothing and the stream just ends.
 */
class LiveHandler
{
    public const TYPES = ['context_update', 'turn_final', 'turn_partial', 'help_now', 'regenerate', 'shorter', 'formal', 'translate', 'cancel', 'practice_next'];

    public function __construct(private readonly LiveContext $context) {}

    /** @param  array<string, mixed>  $message */
    public function handle(AssistSession $session, array $message, EventStream $stream): void
    {
        match ($message['type'] ?? null) {
            'context_update' => $this->contextUpdate($session, $message, $stream),
            'turn_final' => $this->turnFinal($session, $message, $stream),
            'turn_partial' => $this->turnPartial($session, $message, $stream),
            default => $stream->error('bad_message', 'That message is not supported.'),
        };
    }

    /** @param  array<string, mixed>  $message */
    private function contextUpdate(AssistSession $session, array $message, EventStream $stream): void
    {
        $error = $this->context->update($session, $message);
        if ($error === 'documents_too_large') {
            $stream->error('documents_too_large', 'Those documents are too large. Send the parts that matter.');
        } elseif ($error !== null) {
            $stream->error('bad_message', 'That update could not be read.');
        }
    }

    /** @param  array<string, mixed>  $message */
    private function turnFinal(AssistSession $session, array $message, EventStream $stream): void
    {
        $turn = self::turn($message);
        if ($turn === null) {
            $stream->error('bad_message', 'That turn could not be read.');

            return;
        }
        AssistTurn::query()->firstOrCreate(
            ['assist_session_id' => $session->id, 'client_ref' => $turn['id']],
            ['channel' => $turn['channel'], 'start_ms' => $turn['start_ms'], 'end_ms' => $turn['end_ms'], 'text' => $turn['text'], 'language' => $turn['language'], 'is_question' => false],
        );
        if ($turn['channel'] === 'me') {
            $this->context->meSpoke($session, (int) floor(microtime(true) * 1000));
        }
    }

    /**
     * `turn_partial` is only for Me (it tells a held question or an upgrade that the moment has passed).
     *
     * @param  array<string, mixed>  $message
     */
    private function turnPartial(AssistSession $session, array $message, EventStream $stream): void
    {
        $turn = self::turn($message, false);
        if ($turn === null) {
            $stream->error('bad_message', 'That turn could not be read.');

            return;
        }
        if ($turn['channel'] === 'me' && trim($turn['text']) !== '') {
            $this->context->meSpoke($session, (int) floor(microtime(true) * 1000));
        }
    }

    /**
     * A turn message as the relay reads it: ids 1 to 64 characters, text up to 4,000, the `client` and `lang` aliases.
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, channel: string, text: string, language: string|null, start_ms: int, end_ms: int}|null
     */
    private static function turn(array $message, bool $needText = true): ?array
    {
        $id = $message['turn_id'] ?? null;
        $channel = $message['channel'] ?? null;
        $channel = $channel === 'client' ? 'other' : $channel;
        $text = $message['text'] ?? '';
        if (! is_string($id) || $id === '' || mb_strlen($id) > 64 || ! in_array($channel, ['me', 'other'], true)
            || ! is_string($text) || mb_strlen($text) > 4000 || ($needText && trim($text) === '')) {
            return null;
        }
        $language = $message['language'] ?? $message['lang'] ?? null;
        $start = max(0, min(86400000, (int) ($message['start_ms'] ?? 0)));

        return ['id' => $id, 'channel' => $channel, 'text' => trim($text), 'language' => is_string($language) ? mb_substr($language, 0, 16) : null,
            'start_ms' => $start, 'end_ms' => max($start, min(86400000, (int) ($message['end_ms'] ?? $start)))];
    }
}
