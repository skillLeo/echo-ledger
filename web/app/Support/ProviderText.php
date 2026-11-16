<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ClientAi\ClaudeClientAi;

/**
 * Text that names a speech or AI provider, a model or a credential never reaches people (since v1.8.1). An error kept
 * with a call note, a brief, a project file or a transcript's views is read through here: one that names one (kept
 * from before v1.8.1, or written by a library) says the plain "The AI could not do this right now" instead. The
 * operator finds the provider's own words in the server log.
 */
final class ProviderText
{
    /** Provider and model names, and credential names ("Missing credential anthropic.api_key"). */
    private const PATTERN = '/\b(?:Claude|ChatGPT|OpenAI|Anthropic|Gemini|ElevenLabs|Groq|AssemblyAI|Soniox|Azure|Whisper|Parakeet|Haiku|Sonnet)\b|\bclaude-[a-z]|\bMissing credential\b|\b[a-z_]+\.api_key\b/i';

    public static function names(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }

    /** [$text] as people may read it: null stays null, text that names a provider or a credential is [$fallback]. */
    public static function forPeople(?string $text, string $fallback = ClaudeClientAi::UNAVAILABLE): ?string
    {
        return $text === null || ! self::names($text) ? $text : $fallback;
    }
}
