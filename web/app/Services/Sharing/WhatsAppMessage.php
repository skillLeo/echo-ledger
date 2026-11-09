<?php

declare(strict_types=1);

namespace App\Services\Sharing;

use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;

/**
 * Send to WhatsApp from the console (since v1.6, P5.5), as the desktop does it: `wa.me` opens WhatsApp (the app, else
 * WhatsApp Web) with the text ready to send. The text travels in the address, and Windows and the WhatsApp app take only
 * so long an address, so a long transcript is cut at a word with "…" (the page copies the whole text and says so).
 */
final class WhatsAppMessage
{
    /** The longest address handed over: under Windows' 2,048 characters for a URL given to an app, as on the desktop. */
    public const MAX_URL_CHARS = 1900;

    public const BASE = 'https://wa.me/?text=';

    private const ELLIPSIS = '…';

    /** A link from Send to WhatsApp works this long (the phone's and the desktop's choice). */
    public const LINK_SECONDS = 7 * 24 * 3600;

    /**
     * The `wa.me` address for [$text] (trimmed), and whether the text had to be cut to fit. A cut ends at the last space
     * or line break that fits in the second half of what fits (never inside a word or a letter), followed by "…".
     *
     * @return array{url: string, cut: bool}
     */
    public static function url(string $text): array
    {
        $text = trim($text);
        $whole = self::BASE.rawurlencode($text);
        if (strlen($whole) <= self::MAX_URL_CHARS) {
            return ['url' => $whole, 'cut' => false];
        }
        $room = self::MAX_URL_CHARS - strlen(self::BASE) - strlen(rawurlencode(self::ELLIPSIS));
        $head = '';
        $used = 0;
        foreach (mb_str_split($text) as $char) {
            $size = strlen(rawurlencode($char));
            if ($used + $size > $room) {
                break;
            }
            $used += $size;
            $head .= $char;
        }
        $space = max((int) mb_strrpos($head, ' '), (int) mb_strrpos($head, "\n"), (int) mb_strrpos($head, "\t"));
        $cut = $space > intdiv(mb_strlen($head), 2) ? mb_substr($head, 0, $space) : $head;

        return ['url' => self::BASE.rawurlencode(rtrim($cut)).rawurlencode(self::ELLIPSIS), 'cut' => true];
    }

    /** The message for a link: the recording's title on its own line, then the link. */
    public static function linkMessage(?string $title, string $link): string
    {
        $title = trim((string) $title);

        return $title !== '' ? $title."\n".$link : $link;
    }

    /**
     * The transcript as the person reads it: the Show as [$view] when it is made (else the original words), one line
     * each, with the speaker's name when more than one person speaks, unsure words marked as in Copy text. Null while
     * there is no finished transcript.
     */
    public static function transcriptText(CaptureSession $session, ?string $view): ?string
    {
        $final = $session->finalTranscript()->first();
        if ($final === null || $final->status !== TranscriptStatus::Completed || ($final->segments ?? []) === []) {
            return null;
        }
        $view = self::readyView($session, $view);
        $exporter = app(TranscriptExporter::class);
        $speakers = count(array_unique(array_column($exporter->lines($final, $session, $view), 'speaker'))) > 1;
        $text = trim($exporter->export($final, 'txt', ['timestamps' => false, 'speakers' => $speakers, 'view' => $view])['body']);

        return $text === '' ? null : $text;
    }

    /** [$view] when it is a Show as view made for this recording; null (the original words) otherwise. */
    public static function readyView(CaptureSession $session, ?string $view): ?string
    {
        if ($view === null || ! array_key_exists($view, TranscriptViews::VIEWS)) {
            return null;
        }
        $final = $session->finalTranscript()->first();
        if ($final === null) {
            return null;
        }
        $state = app(TranscriptViews::class)->state($final);

        return $state['status'] === 'ready' && ($state[$view] ?? null) !== null ? $view : null;
    }

    /** The view a recording opens in (Settings → Transcription) when it is made, else null: the Library row's text. */
    public static function defaultView(CaptureSession $session): ?string
    {
        $final = $session->finalTranscript()->first();

        return $final === null ? null : self::readyView($session, (string) app(TranscriptViews::class)->state($final)['default']);
    }
}
