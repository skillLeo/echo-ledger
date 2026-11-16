<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ClientAi\ClaudeClientAi;
use App\Services\ClientAi\ClientWorkspace;

/**
 * Text that names a speech or AI provider, a model or a credential, or that is the provider's own error JSON, never
 * reaches people (since v1.8.1). An error kept with a call note, a brief, a project file or a transcript's views is
 * read through here: one about a missing or refused credential ("Missing credential anthropic.api_key") says "AI is not
 * set up on this server yet" (ClientWorkspace::NOT_SET_UP), any other that names a provider (kept from before v1.8.1,
 * or written by a library) says the plain "The AI could not do this right now" instead. The operator finds the
 * provider's own words in the server log.
 */
final class ProviderText
{
    /** A missing or refused credential: the server is not set up, trying again later does not help. */
    private const CREDENTIAL = '/\bMissing credential\b|\b[a-z_]+\.api_key\b|\bauthentication_error\b|\bpermission_error\b|\binvalid x-api-key\b|\bAPI key\b/i';

    /**
     * Provider and model names, and the provider's own error JSON as the SDK writes it into an exception message
     * ({"status": 529, "body": {"type": "error", "error": {"type": "overloaded_error", …}}}, pretty-printed).
     */
    private const NAMES = '/\b(?:Claude|ChatGPT|OpenAI|Anthropic|Gemini|ElevenLabs|Groq|AssemblyAI|Soniox|Azure|Whisper|Parakeet|Haiku|Sonnet)\b|\bclaude-[a-z]|"type"\s*:\s*"[a-z_]+_error"|\{\s*"status"\s*:/i';

    public static function names(string $text): bool
    {
        return preg_match(self::NAMES, $text) === 1 || self::credential($text);
    }

    /** True when [$text] is about a missing or refused credential. */
    public static function credential(string $text): bool
    {
        return preg_match(self::CREDENTIAL, $text) === 1;
    }

    /**
     * [$text] as people may read it: null stays null, text about a credential is ClientWorkspace::NOT_SET_UP, other
     * text that names a provider or is its JSON is [$fallback].
     */
    public static function forPeople(?string $text, string $fallback = ClaudeClientAi::UNAVAILABLE): ?string
    {
        return match (true) {
            $text === null => null,
            self::credential($text) => ClientWorkspace::NOT_SET_UP,
            self::names($text) => $fallback,
            default => $text,
        };
    }
}
