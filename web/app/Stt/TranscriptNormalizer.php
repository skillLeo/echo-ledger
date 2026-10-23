<?php

declare(strict_types=1);

namespace App\Stt;

use App\Stt\Data\Segment;
use App\Stt\Data\Word;

/**
 * Provider-neutral helpers that turn word streams into the shared segment shape (docs/API.md "Segment").
 */
final class TranscriptNormalizer
{
    public static function speakerFor(int $channel, int $channelCount): string
    {
        if ($channelCount < 2) {
            return 'Speaker';
        }

        return $channel === 1 ? 'Me' : 'Client';
    }

    /**
     * Groups one channel's words into segments: split on gaps ≥ $gapMs, or after sentence-ending punctuation
     * once a segment is long enough.
     *
     * @param  list<Word>  $words
     * @return list<Segment>
     */
    public static function segmentWords(array $words, int $channel, string $speaker, int $gapMs = 1000, int $softMaxMs = 12000): array
    {
        $segments = [];
        $current = [];
        foreach ($words as $word) {
            if ($current !== []) {
                $last = $current[count($current) - 1];
                $tooLong = ($last->endMs - $current[0]->startMs) >= $softMaxMs && preg_match('/[.!?…]$/u', $last->text) === 1;
                if ($word->startMs - $last->endMs >= $gapMs || $tooLong) {
                    $segments[] = self::makeSegment($current, $channel, $speaker);
                    $current = [];
                }
            }
            $current[] = $word;
        }
        if ($current !== []) {
            $segments[] = self::makeSegment($current, $channel, $speaker);
        }

        return $segments;
    }

    /** @param non-empty-list<Word> $words */
    private static function makeSegment(array $words, int $channel, string $speaker): Segment
    {
        $text = trim((string) preg_replace('/\s+([,.!?;:…])/u', '$1', implode(' ', array_map(fn (Word $w) => $w->text, $words))));

        return new Segment($channel, $speaker, $words[0]->startMs, $words[count($words) - 1]->endMs, $text, $words);
    }

    /**
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    public static function sortByStart(array $segments): array
    {
        usort($segments, fn (Segment $a, Segment $b): int => [$a->startMs, $a->channel] <=> [$b->startMs, $b->channel]);

        return $segments;
    }

    /**
     * Acoustic-bleed dedupe (PLAN.md D13): a desktop mic can re-record the client's voice from the speakers,
     * duplicating "Client" speech on the "Me" channel. Drop a channel-1 segment when its text closely matches an
     * overlapping channel-2 segment.
     *
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    public static function dedupeBleed(array $segments, float $threshold = 80.0): array
    {
        $clients = array_values(array_filter($segments, fn (Segment $s) => $s->channel === 2));
        if ($clients === []) {
            return $segments;
        }

        return array_values(array_filter($segments, function (Segment $s) use ($clients, $threshold): bool {
            if ($s->channel !== 1) {
                return true;
            }
            $mine = self::comparable($s->text);
            if (mb_strlen($mine) < 8) {
                return true; // too short to judge ("yes", "ok")
            }
            foreach ($clients as $c) {
                $overlaps = $c->startMs <= $s->endMs + 500 && $s->startMs <= $c->endMs + 500;
                if ($overlaps) {
                    similar_text($mine, self::comparable($c->text), $percent);
                    if ($percent >= $threshold) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    private static function comparable(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N} ]+/u', '', mb_strtolower($text)));
    }

    /** @param list<Segment> $segments */
    public static function plainText(array $segments): string
    {
        $lines = [];
        foreach ($segments as $s) {
            $lines[] = $s->speaker.': '.$s->text;
        }

        return implode("\n", $lines);
    }

    public static function secondsToMs(float|int|string|null $seconds): int
    {
        return (int) round(((float) $seconds) * 1000);
    }
}
