<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
        $data = is_array($data) ? $data : ['brief' => [], 'settings' => [], 'documents' => [], 'memory' => '', 'facts' => [], 'summary' => ''];
        $me = Cache::get(self::meKey($session));

        return [...$data, 'last_me_ms' => is_int($me) ? $me : null];
    }

    /**
     * Applies a `context_update` message: the fields present replace.
     *
     * @param  array<string, mixed>  $message
     * @return string|null an error code (`documents_too_large`, `bad_message`) with nothing changed, or null
     */
    public function update(AssistSession $session, array $message): ?string
    {
        return $this->locked($session, fn (): ?string => $this->alive($session) ? $this->apply($session, $message) : null);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function apply(AssistSession $session, array $message): ?string
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
        // A settings update changes only the settings it names (the hint level stays when only the mode is sent).
        if (array_key_exists('settings', $new)) {
            $new['settings'] = [...(array) ($context['settings'] ?? []), ...(array) $new['settings']];
        }
        $this->put($session, [...$context, ...$new]);

        return null;
    }

    /**
     * Adds the facts the facts call found to the ledger the suggestions read: those already there (ignoring case and
     * spacing) are left out, and at most MAX_FACTS are kept, the oldest dropped first. Nothing is written for a session
     * that has ended or been erased since the call began: what the call told the server went with it (see forget()).
     *
     * @param  list<string>  $new
     * @return list<string>|null the whole ledger, or null when nothing was written
     */
    public function addFacts(AssistSession $session, array $new): ?array
    {
        return $this->locked($session, function () use ($session, $new): ?array {
            if (! $this->alive($session)) {
                return null;
            }
            $context = $this->get($session);
            $facts = array_values(array_map('strval', (array) ($context['facts'] ?? [])));
            $known = array_map(Text::normalize(...), $facts);
            foreach ($new as $fact) {
                if (! in_array(Text::normalize($fact), $known, true)) {
                    $facts[] = $fact;
                    $known[] = Text::normalize($fact);
                }
            }
            $facts = array_slice($facts, -self::MAX_FACTS);
            $this->put($session, [...$context, 'facts' => $facts]);

            return $facts;
        });
    }

    /**
     * The running summary the summary call wrote: it replaces the one the suggestions read. Not written for a session
     * that has ended or been erased since the call began.
     *
     * @return bool whether it was written
     */
    public function setSummary(AssistSession $session, string $summary): bool
    {
        return $this->locked($session, function () use ($session, $summary): bool {
            if (! $this->alive($session)) {
                return false;
            }
            $this->put($session, [...$this->get($session), 'summary' => $summary]);

            return true;
        });
    }

    /** When Me last spoke (a wall-clock millisecond stamp), or null: only this, without reading the whole context. */
    public function lastMe(AssistSession $session): ?int
    {
        $me = Cache::get(self::meKey($session));

        return is_int($me) ? $me : null;
    }

    /**
     * Remembers that Me spoke at [$ms] (a wall-clock millisecond stamp), apart from the rest of the context: several
     * requests of one session run at once, and this is the one thing they all write.
     */
    public function meSpoke(AssistSession $session, int $ms): void
    {
        Cache::put(self::meKey($session), $ms, self::TTL_SECONDS);
    }

    /** @param  array<string, mixed>  $context */
    public function put(AssistSession $session, array $context): void
    {
        unset($context['last_me_ms']);
        Cache::put(self::key($session), Crypt::encryptString((string) json_encode($context, JSON_UNESCAPED_UNICODE)), self::TTL_SECONDS);
    }

    /**
     * What the call told the server goes with the session. Under the lock the writers take, and after the session is
     * marked ended: a writer that was ahead of it is cleared here, one behind it finds the session over (alive()).
     */
    public function forget(AssistSession $session): void
    {
        $this->locked($session, function () use ($session): void {
            Cache::forget(self::key($session));
            Cache::forget(self::meKey($session));
        });
    }

    /** The session as it is now, in the database: still on, not ended and not erased (soft deleted). */
    private function alive(AssistSession $session): bool
    {
        $fresh = AssistSession::query()->withoutGlobalScopes()->withTrashed()->find($session->id);

        return $fresh instanceof AssistSession && ! $fresh->trashed() && ! $fresh->ended();
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $change
     * @return T
     */
    private function locked(AssistSession $session, \Closure $change): mixed
    {
        try {
            return Cache::lock('assist.live.context.lock.'.$session->id, 10)->block(3, $change);
        } catch (LockTimeoutException) {
            return $change();
        }
    }

    private static function meKey(AssistSession $session): string
    {
        return 'assist.live.me.'.$session->id;
    }

    private static function key(AssistSession $session): string
    {
        return 'assist.live.context.'.$session->id;
    }
}
