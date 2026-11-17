<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

/**
 * What asked for a suggestion, with the words it answers (services/assist-relay/src/session.rs `Origin`): the end of
 * the other side's turn, "help now", or a rewrite of an earlier suggestion. HTTP mode has no speculative starts.
 */
final readonly class Origin
{
    public const ANSWER = 'answer';

    public const HELP_NOW = 'help_now';

    public const REWRITES = ['regenerate', 'shorter', 'formal', 'translate'];

    /**
     * @param  string  $kind  answer | help_now | regenerate | shorter | formal | translate
     * @param  string  $question  Them's words (empty for help now before they said anything)
     * @param  string|null  $previous  a rewrite's earlier suggestion text
     * @param  string|null  $language  a translation's language (urdu, roman_urdu, arabic)
     */
    public function __construct(
        public string $kind,
        public ?string $turnId,
        public string $question,
        public ?string $previous = null,
        public ?string $language = null,
    ) {}

    public static function answer(string $turnId, string $text): self
    {
        return new self(self::ANSWER, $turnId, $text);
    }

    public static function helpNow(?string $turnId, string $question): self
    {
        return new self(self::HELP_NOW, $turnId, $question);
    }

    public static function rewrite(string $kind, ?string $turnId, string $question, string $previous, ?string $language = null): self
    {
        return new self($kind, $turnId, $question, $previous, $language);
    }

    /** Asked for by Me, so Me hears back even when no suggestion can be made. */
    public function explicit(): bool
    {
        return $this->kind !== self::ANSWER;
    }

    public function isRewrite(): bool
    {
        return in_array($this->kind, self::REWRITES, true);
    }
}
