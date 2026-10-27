<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * Whisper's own confidence figures, used the way Whisper's reference decoder uses them (openai/whisper
 * transcribe.py defaults): a segment is silence when `no_speech_prob` > 0.6 and `avg_logprob` < -1.0, and a looping
 * one when `compression_ratio` > 2.4. Such text is invented, not heard: it is removed and logged. A phrase that
 * repeats in a loop ("thank you thank you thank you …") is kept once. Low-confidence text is kept but marked
 * uncertain; nothing is ever rewritten. Pure; unit-tested.
 */
final class WhisperGuards
{
    public const NO_SPEECH = 0.6;

    public const LOGPROB_SILENT = -1.0;

    public const COMPRESSION = 2.4;

    /** Kept text below this average log-probability is marked uncertain. */
    public const LOGPROB_UNCERTAIN = -0.8;

    /** The same text this many times in a row is a loop. */
    public const REPEATS = 3;

    /**
     * @param  list<array<string, mixed>>  $segments  Groq/OpenAI verbose_json segments (text, start, end, avg_logprob, …)
     * @return array{kept: list<array<string, mixed>>, removed: list<array{text: string, reason: string, start: float}>}
     */
    public static function filter(array $segments): array
    {
        $kept = [];
        $removed = [];
        $previous = null;
        $run = 0;
        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            $start = (float) ($segment['start'] ?? 0);
            $logprob = (float) ($segment['avg_logprob'] ?? 0);
            $reason = match (true) {
                $text === '' => null,
                (float) ($segment['no_speech_prob'] ?? 0) > self::NO_SPEECH && $logprob < self::LOGPROB_SILENT => 'silence',
                (float) ($segment['compression_ratio'] ?? 0) > self::COMPRESSION => 'loop',
                default => null,
            };
            if ($text === '') {
                continue;
            }
            $normal = mb_strtolower(preg_replace('/[\p{P}\s]+/u', ' ', $text) ?? $text);
            $run = $normal === $previous ? $run + 1 : 1;
            $previous = $normal;
            if ($reason === null && $run >= self::REPEATS) {
                $reason = 'repeat';
            }
            if ($reason !== null) {
                $removed[] = ['text' => mb_substr($text, 0, 120), 'reason' => $reason, 'start' => $start];

                continue;
            }
            $collapsed = self::collapseLoop($text);
            $segment['text'] = $collapsed;
            $segment['uncertain'] = $logprob < self::LOGPROB_UNCERTAIN;
            if ($collapsed !== $text) {
                $removed[] = ['text' => mb_substr($text, 0, 120), 'reason' => 'repeat', 'start' => $start];
            }
            $kept[] = $segment;
        }

        return ['kept' => $kept, 'removed' => $removed];
    }

    /** A phrase of one to eight words repeated four or more times in a row inside one segment, kept once. */
    public static function collapseLoop(string $text): string
    {
        return preg_replace('/(?<!\S)((?:\S+\s+){0,7}?\S+)(?:\s+\1(?!\S)){3,}/u', '$1', $text) ?? $text;
    }
}
