<?php

declare(strict_types=1);

// The plain-words rule's vendor names (PlainWordsTest), shared with the website's check (WebsiteV2Test).

if (defined('VENDOR_NAMES')) {
    return;
}

/** Provider, model and vendor names people must not read. */
const VENDOR_NAMES = '/\b(?:Claude|ChatGPT|OpenAI|Anthropic|Gemini|ElevenLabs|Groq|AssemblyAI|Soniox|Azure|Whisper|Parakeet|Haiku|Sonnet|universal-\d|claude-[a-z]+)\b/';

/** The vendor names in [$text], leaving out the one people act on: the Free plan's own Groq key, and where to make it. */
function vendorNamesIn(string $text): array
{
    $text = (string) preg_replace(['/\bGroq key\b/', '/\bconsole\.groq\.com\b/'], '', $text);
    preg_match_all(VENDOR_NAMES, $text, $found);

    return array_values(array_unique($found[0]));
}
