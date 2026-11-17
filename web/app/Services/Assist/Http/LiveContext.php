<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * What the app told HTTP mode about a call, apart from the transcript (docs/API_ASSIST.md `context_update`): brief,
 * settings, documents, memory, facts, summary. A field present in an update replaces what was there. Kept encrypted in
 * the cache for the session's life (text is user data), with the moment Me last spoke.
 */
class LiveContext
{
    public const FIELDS = ['brief', 'settings', 'documents', 'memory', 'facts', 'summary'];

    public const TTL_SECONDS = 10800;

    public const MAX_DOCUMENTS = 20;

    public const MAX_DOCUMENT_CHARS = 400000;

    public const MAX_DOCUMENT_TOKENS = 60000;

    public const MAX_FACTS = 200;

    public const MAX_FACT_CHARS = 1000;

    public const MAX_FIELD_CHARS = 20000;

    /** @return array<string, mixed> */
    public function get(AssistSession $session): array
    {
        $stored = Cache::get(self::key($session));
        $data = is_string($stored) ? json_decode(Crypt::decryptString($stored), true) : null;

        return is_array($data) ? $data : ['brief' => [], 'settings' => [], 'documents' => [], 'memory' => '', 'facts' => [], 'summary' => '', 'last_me_ms' => null];
    }

    /**
     * Applies a `context_update` message: the fields present replace.
     *
     * @param  array<string, mixed>  $message
     * @return string|null an error code (`documents_too_large`, `bad_message`) with nothing changed, or null
     */
    public function update(AssistSession $session, array $message): ?string
    {
        $context = $this->get($session);
        $new = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $message)) {
                // An empty value clears (the framework turns an empty string into null on the way in).
                $new[$field] = $message[$field] ?? match ($field) {
                    'memory', 'summary' => '',
                    default => [],
                };
            }
        }
        if (array_key_exists('documents', $new)) {
            if (! is_array($new['documents']) || ! array_is_list($new['documents'])) {
                return 'bad_message';
            }
            $tokens = 0;
            foreach ($new['documents'] as $document) {
                if (! is_array($document) || ! is_string($document['text'] ?? null)) {
                    return 'bad_message';
                }
                if (mb_strlen($document['text']) > self::MAX_DOCUMENT_CHARS) {
                    return 'documents_too_large';
                }
                $tokens += Pricing::estimateTokens($document['text']);
            }
            if (count($new['documents']) > self::MAX_DOCUMENTS || $tokens > self::MAX_DOCUMENT_TOKENS) {
                return 'documents_too_large';
            }
            $new['documents'] = array_map(fn (array $d): array => ['id' => (string) ($d['id'] ?? ''), 'title' => (string) ($d['title'] ?? ''), 'text' => $d['text']], $new['documents']);
        }
        if (array_key_exists('facts', $new)) {
            if (! is_array($new['facts']) || count($new['facts']) > self::MAX_FACTS) {
                return 'bad_message';
            }
            $facts = [];
            foreach ($new['facts'] as $fact) {
                $text = is_array($fact) ? ($fact['text'] ?? null) : $fact;
                if (! is_string($text) || mb_strlen($text) > self::MAX_FACT_CHARS) {
                    return 'bad_message';
                }
                $facts[] = $text;
            }
            $new['facts'] = $facts;
        }
        foreach (['memory', 'summary'] as $text) {
            if (array_key_exists($text, $new) && (! is_string($new[$text]) || mb_strlen($new[$text]) > self::MAX_FIELD_CHARS)) {
                return 'bad_message';
            }
        }
        foreach (['brief', 'settings'] as $object) {
            if (array_key_exists($object, $new)) {
                if (! is_array($new[$object])) {
                    return 'bad_message';
                }
                foreach ($new[$object] as $value) {
                    if (is_string($value) && mb_strlen($value) > self::MAX_FIELD_CHARS) {
                        return 'bad_message';
                    }
                }
            }
        }
        $this->put($session, [...$context, ...$new]);

        return null;
    }

    /** Remembers that Me spoke at [$ms] (a wall-clock millisecond stamp). */
    public function meSpoke(AssistSession $session, int $ms): void
    {
        $this->put($session, [...$this->get($session), 'last_me_ms' => $ms]);
    }

    /** @param  array<string, mixed>  $context */
    public function put(AssistSession $session, array $context): void
    {
        Cache::put(self::key($session), Crypt::encryptString((string) json_encode($context, JSON_UNESCAPED_UNICODE)), self::TTL_SECONDS);
    }

    public function forget(AssistSession $session): void
    {
        Cache::forget(self::key($session));
    }

    private static function key(AssistSession $session): string
    {
        return 'assist.live.context.'.$session->id;
    }
}
