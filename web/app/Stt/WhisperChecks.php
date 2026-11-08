<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * Checks of a Whisper answer against the audio it was given (since v1.5, after a shared 18.7 s Urdu voice note came
 * back as «ٹھیک ہے، میں email بھیج دوں گا۔», the last words of our own style prompt, and as "main milte hoon" in a
 * loop). An answer that fails one is never kept as it is: the chunk is asked again without a prompt, and if that fails
 * too the engine's run is refused so the next engine writes the transcript.
 */
final class WhisperChecks
{
    /** The engine's own length of what it heard may differ from what was sent by this much (plus 0.25 s). */
    public const DURATION_TOLERANCE = 0.02;

    /** Fewer words a second of speech than this is not a transcript of it (speech is about 2-3 words a second). */
    public const MIN_WORDS_PER_SPEECH_SECOND = 0.6;

    /** Below this much speech the words-per-second check says nothing (a "yes" is one word). */
    public const MIN_SPEECH_S = 4.0;

    /**
     * The words check is for short pieces only: a long one can be mostly hold music or a noisy line above the silence
     * threshold, where few words are right.
     */
    public const MAX_WORDS_CHECK_S = 60.0;

    /** At least this share of the answer's words also in the prompt, in order, is an echo of the prompt. */
    public const ECHO_SHARE = 0.6;

    /**
     * What is wrong with an answer, or null: `duration` (it heard another length than was sent: a conversion bug),
     * `prompt_echo`, `loop`, `too_few_words`.
     *
     * @param  array<string, mixed>  $json  Whisper's verbose_json answer
     */
    public static function problem(array $json, float $sentSeconds, float $speechSeconds, string $prompt): ?string
    {
        $text = trim(implode(' ', array_map(fn ($s): string => is_array($s) ? trim((string) ($s['text'] ?? '')) : '', (array) ($json['segments'] ?? []))));
        if ($text === '') {
            $text = trim((string) ($json['text'] ?? ''));
        }
        $heard = $json['duration'] ?? null;
        if (is_numeric($heard) && $sentSeconds > 1.0 && abs((float) $heard - $sentSeconds) > $sentSeconds * self::DURATION_TOLERANCE + 0.25) {
            return 'duration';
        }
        if ($text === '') {
            return null; // silence is not a problem here (WhisperGuards drops what Whisper invents in silence)
        }
        if (self::echoes($text, $prompt)) {
            return 'prompt_echo';
        }
        if (self::loops($text)) {
            return 'loop';
        }
        $words = count(WordAlign::tokens($text));
        if ($speechSeconds >= self::MIN_SPEECH_S && $sentSeconds <= self::MAX_WORDS_CHECK_S && $words / $speechSeconds < self::MIN_WORDS_PER_SPEECH_SECOND) {
            return 'too_few_words';
        }

        return null;
    }

    /** Whether most of [$text]'s words are the prompt's own, in order. */
    public static function echoes(string $text, string $prompt): bool
    {
        $mine = WordAlign::tokens($text);
        $theirs = WordAlign::tokens($prompt);
        if ($mine === [] || $theirs === []) {
            return false;
        }
        $same = count(array_filter(WordAlign::align($mine, $theirs), fn (array $o): bool => $o['op'] === 'same'));

        return $same / count($mine) >= self::ECHO_SHARE;
    }

    /**
     * Whether a phrase of one to six words is said three or more times in a row and fills at least half of the text, or
     * a phrase of two words or more twice in a row filling most of it ("ملتے ہیں، میں ملتے ہوں، میں ملتے ہوں"):
     * Whisper's loop on audio it cannot follow.
     */
    public static function loops(string $text): bool
    {
        $keys = array_values(array_filter(array_map(WordAlign::key(...), WordAlign::tokens($text)), fn (string $k): bool => $k !== ''));
        $n = count($keys);
        if ($n < 4) {
            return false;
        }
        for ($size = 1; $size <= 6; $size++) {
            for ($start = 0; $start + 2 * $size <= $n; $start++) {
                $repeats = 1;
                while ($start + ($repeats + 1) * $size <= $n
                    && array_slice($keys, $start, $size) === array_slice($keys, $start + $repeats * $size, $size)) {
                    $repeats++;
                }
                $covered = $repeats * $size;
                if (($repeats >= 3 && $covered * 2 >= $n) || ($repeats >= 2 && $size >= 2 && $covered * 10 >= $n * 7)) {
                    return true;
                }
            }
        }

        return false;
    }
}
