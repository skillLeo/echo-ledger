<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

/**
 * SKIP: the model answers exactly `SKIP` when no reply is needed. Words are held back only while the answer so far
 * could still become `SKIP` (one or two tokens), so the card never flashes an "S" and real answers are not delayed
 * (services/assist-relay/src/skip.rs).
 */
final class SkipGate
{
    private string $held = '';

    private bool $open = false;

    /** Whether [$text] could still turn out to be `SKIP` (or `SKIP.`), ignoring case and surrounding whitespace. */
    public static function couldBeSkip(string $text): bool
    {
        $trimmed = trim($text);

        return strlen($trimmed) <= 5 && strncasecmp('SKIP.', $trimmed, strlen($trimmed)) === 0;
    }

    /** Whether the whole answer is `SKIP`. */
    public static function isSkip(string $text): bool
    {
        $trimmed = trim($text);

        return strcasecmp($trimmed, 'SKIP') === 0 || strcasecmp($trimmed, 'SKIP.') === 0;
    }

    /** Takes the next chunk; returns what may be sent now (null: nothing yet). */
    public function feed(string $chunk): ?string
    {
        if ($this->open) {
            return $chunk;
        }
        $this->held .= $chunk;
        if (self::couldBeSkip($this->held)) {
            return null;
        }
        $this->open = true;
        $release = $this->held;
        $this->held = '';

        return $release;
    }

    /**
     * At the end of the answer: null when the whole answer was SKIP (send `skip`, no words), otherwise the last held
     * text to send before the end (maybe empty).
     */
    public function finish(): ?string
    {
        if ($this->open) {
            return '';
        }
        if (self::isSkip($this->held)) {
            return null;
        }
        $this->open = true;
        $rest = $this->held;
        $this->held = '';

        return $rest;
    }

    /** Something was sent to the app already. */
    public function isOpen(): bool
    {
        return $this->open;
    }
}
