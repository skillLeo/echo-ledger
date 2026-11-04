<?php

declare(strict_types=1);

namespace App\Services\Accuracy;

use RuntimeException;
use ZipArchive;

/**
 * A reference transcript as it is pasted or uploaded for the Accuracy Lab (since v1.5), ready for the texts of
 * TurboScribe or any other service: plain text, SubRip (.srt), WebVTT (.vtt) or Word (.docx). Only the spoken words
 * stay, line by line: cue numbers, time codes ("00:01:02,500 --> …", "(00:12)", "[01:02:03]") and generic speaker
 * labels ("Speaker 1:", "Speaker 2 (00:05)", "SPEAKER_00:", "Me:", "Client:") go. Pure apart from reading the file.
 */
final class ReferenceText
{
    /** An hour of speech is about 60 KB of text; a Word file carries its own packaging. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const EXTENSIONS = ['txt', 'srt', 'vtt', 'docx'];

    private const TIME = '[\[(]?\d{1,2}(?::\d{2}){1,2}(?:[.,]\d+)?[\])]?';

    private const LABEL = '(?:speaker(?:[ _-]?[0-9a-z]{1,3})?|me|client)';

    public static function clean(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) preg_replace('/^\x{FEFF}/u', '', $raw));
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('/<[^>]+>/u', '', $line)); // WebVTT voice and style tags
            // Blank, the WebVTT header and its blocks, a cue number, a cue's times, or a speaker heading on its own line.
            if ($line === '' || preg_match('/^(WEBVTT|NOTE|STYLE|REGION)\b/', $line) === 1 || preg_match('/^\d+$/', $line) === 1
                || str_contains($line, '-->') || preg_match('/^'.self::LABEL.'\s*(?:'.self::TIME.')?\s*:?$/iu', $line) === 1) {
                continue;
            }
            $line = (string) preg_replace('/^'.self::TIME.'\s*[-–—:]?\s*/u', '', $line);
            $line = (string) preg_replace('/^'.self::LABEL.'\s*(?:'.self::TIME.')?\s*:\s*/iu', '', $line);
            $line = trim((string) preg_replace('/^'.self::TIME.'\s*[-–—:]?\s*/u', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /** The cleaned text of an uploaded file. Throws with a message that is safe to show. */
    public static function fromFile(string $path, string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Upload a .txt, .srt, .vtt or .docx file.');
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new RuntimeException('That file is too large for a transcript.');
        }
        $raw = $extension === 'docx' ? self::docxText($path) : (string) file_get_contents($path);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new RuntimeException('Save the file as UTF-8 text and upload it again.');
        }

        return self::clean($raw);
    }

    /** The paragraphs of a Word file, one per line. */
    private static function docxText(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('That Word file could not be read.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if (! is_string($xml) || $xml === '') {
            throw new RuntimeException('That Word file has no text.');
        }
        $xml = (string) preg_replace(['#</w:p>#', '#<w:(?:tab|br)\b[^>]*/>#'], ["\n", ' '], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
