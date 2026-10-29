<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsRepository;

/**
 * Unsure words in copied and exported text (since v1.3, plan A4; pure apart from [settings]). On screen an unsure word
 * is underlined; in text it becomes "[?word]" (or "word(?)", or stays plain, console setting `unsure.style`), and
 * inaudible audio stays "[unclear]". A word is unsure when the engine scored it below `unsure.threshold`. The views
 * (Roman Urdu, اردو, English) are made from the marked text, so they carry the same markers.
 */
final class UnsureMarkers
{
    public const STYLES = ['brackets', 'suffix', 'off'];

    /** "[?word]" as the views and the marked text store it. */
    public const PATTERN = '/\[\?([^\]]+)\]/u';

    /**
     * @return array{style: string, threshold: float, note: bool} the console's choices
     */
    public static function settings(?SettingsRepository $settings = null): array
    {
        $settings ??= app(SettingsRepository::class);
        $style = $settings->get('unsure.style', 'brackets');
        $threshold = $settings->get('unsure.threshold', 0.5);

        return [
            'style' => in_array($style, self::STYLES, true) ? $style : 'brackets',
            'threshold' => is_numeric($threshold) ? max(0.0, min(1.0, (float) $threshold)) : 0.5,
            'note' => (bool) $settings->get('unsure.note', true),
        ];
    }

    /**
     * A segment's text with its unsure words marked "[?word]": from the words the engine scored (an edited line has
     * none and is returned as it is).
     *
     * @param  array<string, mixed>  $segment
     */
    public static function mark(array $segment, float $threshold): string
    {
        $line = trim((string) ($segment['text'] ?? ''));
        $words = is_array($segment['words'] ?? null) ? $segment['words'] : [];
        // The line's own text is kept exactly (punctuation, words the engine gave no timing for); each unsure word is
        // found in order from where the previous word ended and wrapped there.
        $out = '';
        $at = 0;
        foreach ($words as $word) {
            $text = trim((string) ($word['text'] ?? $word['word'] ?? ''));
            $pos = $text === '' ? false : mb_strpos($line, $text, $at);
            if ($pos === false) {
                continue;
            }
            $confidence = $word['confidence'] ?? null;
            $unsure = is_numeric($confidence) && (float) $confidence < $threshold && ! str_starts_with($text, '[');
            $out .= mb_substr($line, $at, $pos - $at).($unsure ? '[?'.$text.']' : $text);
            $at = $pos + mb_strlen($text);
        }

        return $out.mb_substr($line, $at);
    }

    /** "[?word]" in the chosen style: kept, "word(?)", or plain. */
    public static function restyle(string $text, string $style): string
    {
        return match ($style) {
            'suffix' => (string) preg_replace(self::PATTERN, '$1(?)', $text),
            'off' => (string) preg_replace(self::PATTERN, '$1', $text),
            default => $text,
        };
    }

    /** Whether the text has a marker the note line explains. */
    public static function hasMarkers(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1 || str_contains($text, '[unclear') || str_contains($text, '(?)');
    }

    /** The first line of "Copy for AI" (and of exports when the note is on), in the chosen style. */
    public static function note(string $style): string
    {
        $word = $style === 'suffix' ? 'word(?)' : '[?word]';

        return "Note: {$word} = the transcription is unsure about this word; [unclear] = inaudible audio.";
    }
}
