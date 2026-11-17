<?php

declare(strict_types=1);

namespace App\Services\Screens;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Screen;
use App\Models\Transcript;
use App\Services\TranscriptExporter;
use Illuminate\Support\Str;

/**
 * What a screen moment is called and what was said around it (v1.8 P4.2), the same in the API, the meeting pack, the
 * meeting folder, Copy for AI and the Claude connector:
 *
 * - its file name, `S07_14-32_orders-page.webp`: its number (as many digits as the last one needs, at least two), its
 *   moment in the recording (`h-mm-ss` past an hour) and a short slug of its description (`screen` without one);
 * - what was said within 15 seconds of it, from the final transcript (else the live one), speaker by speaker.
 */
final class ScreenAssets
{
    /** What was said this long before and after the screen. */
    public const SAID_WINDOW_MS = 15000;

    /** At most this many characters of it. */
    public const SAID_CHARS = 600;

    public const SLUG_CHARS = 40;

    public static function fileName(Screen $screen, int $number, int $count): string
    {
        $slug = Str::slug(Str::limit((string) $screen->description, 120, ''));
        if (mb_strlen($slug) > self::SLUG_CHARS) {
            // At a word, without a dangling dash.
            $slug = rtrim((string) preg_replace('/-[^-]*$/', '', mb_substr($slug, 0, self::SLUG_CHARS + 1)), '-');
        }

        return sprintf('S%0'.max(2, strlen((string) $count)).'d_%s_%s.%s', $number, self::clock($screen->at_ms), $slug !== '' ? $slug : 'screen', $screen->extension());
    }

    /** `14-32`, or `1-04-32` past an hour: the moment, as a file name can hold it. */
    public static function clock(int $ms): string
    {
        return str_replace(':', '-', TranscriptExporter::clock($ms));
    }

    /**
     * The lines said within 15 seconds of [$screen], as "Speaker: words", at most 600 characters; null when none.
     *
     * @param  list<array{speaker: string, start_ms: int, end_ms: int, text: string}>  $lines
     */
    public static function said(Screen $screen, array $lines): ?string
    {
        $from = $screen->at_ms - self::SAID_WINDOW_MS;
        $to = $screen->at_ms + self::SAID_WINDOW_MS;
        $said = [];
        foreach ($lines as $line) {
            if ($line['end_ms'] >= $from && $line['start_ms'] <= $to) {
                $said[] = (trim($line['speaker']) !== '' ? trim($line['speaker']).': ' : '').trim($line['text']);
            }
        }
        if ($said === []) {
            return null;
        }
        $text = implode("\n", $said);

        return mb_strlen($text) > self::SAID_CHARS ? rtrim(mb_substr($text, 0, self::SAID_CHARS - 1)).'…' : $text;
    }

    /**
     * The recording's lines for `said`: its finished final transcript, else its live one; none without either.
     *
     * @return list<array{index: int, speaker: string, label: string, channel: int, start_ms: int, end_ms: int, text: string}>
     */
    public static function lines(CaptureSession $session): array
    {
        $transcript = $session->transcripts()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)->first()
            ?? $session->transcripts()->where('kind', TranscriptKind::Live->value)->latest('id')->first();
        if (! $transcript instanceof Transcript) {
            return [];
        }
        $transcript->setRelation('captureSession', $session);

        return app(TranscriptExporter::class)->lines($transcript, $session);
    }
}
