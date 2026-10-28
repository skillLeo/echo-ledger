<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaptureSession;
use App\Models\SessionMarker;
use App\Models\Transcript;
use App\Services\Views\TranscriptViews;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Transcript exports (docs/API.md "GET /api/transcripts/{id}/export", since v1.1): TXT, Markdown, SRT, VTT, JSON,
 * DOCX and a print-ready HTML page (the browser or the app saves it as PDF, which keeps Urdu and Hindi shaping
 * right). Speaker names come from the session's renames.
 */
final class TranscriptExporter
{
    public const FORMATS = ['txt', 'md', 'srt', 'vtt', 'json', 'docx', 'html'];

    private const MIME = [
        'txt' => 'text/plain; charset=UTF-8',
        'md' => 'text/markdown; charset=UTF-8',
        'srt' => 'application/x-subrip; charset=UTF-8',
        'vtt' => 'text/vtt; charset=UTF-8',
        'json' => 'application/json',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'html' => 'text/html; charset=UTF-8',
    ];

    public function __construct(private readonly TranscriptEditor $editor, private readonly SettingsRepository $settings) {}

    /**
     * @param  array{timestamps?: bool, speakers?: bool, view?: string|null}  $options  `view`: roman or english (Show as), when ready
     * @return array{body: string, mime: string, filename: string}
     */
    public function export(Transcript $transcript, string $format, array $options = []): array
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new RuntimeException("Unknown export format {$format}.");
        }
        $session = $transcript->captureSession;
        $timestamps = $options['timestamps'] ?? true;
        $speakers = $options['speakers'] ?? true;
        $lines = $this->lines($transcript, $session, $options['view'] ?? null);
        $body = match ($format) {
            'txt' => $this->txt($lines, $timestamps, $speakers),
            'md' => $this->markdown($session, $lines, $timestamps, $speakers),
            'srt' => $this->srt($lines, $speakers),
            'vtt' => $this->vtt($lines, $speakers),
            'json' => $this->json($session, $transcript, $lines),
            'docx' => $this->docx($session, $lines, $timestamps, $speakers),
            'html' => $this->html($session, $lines, $timestamps, $speakers),
        };

        return ['body' => $body, 'mime' => self::MIME[$format], 'filename' => self::filename($session, $format)];
    }

    /**
     * The segments with the speaker names applied, in the "Show as" view when one is asked for and ready.
     *
     * @return list<array{speaker: string, label: string, channel: int, start_ms: int, end_ms: int, text: string}>
     */
    public function lines(Transcript $transcript, CaptureSession $session, ?string $view = null): array
    {
        $names = $this->editor->speakerNames($session);
        $texts = $view !== null ? app(TranscriptViews::class)->textsFor($transcript, $view) : null;
        $lines = [];
        foreach ($transcript->segments ?? [] as $i => $segment) {
            $label = (string) ($segment['speaker'] ?? '');
            $text = trim((string) ($texts[$i] ?? $segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = [
                'speaker' => $names[$label] ?? $label,
                'label' => $label,
                'channel' => (int) ($segment['channel'] ?? 1),
                'start_ms' => max(0, (int) ($segment['start_ms'] ?? 0)),
                'end_ms' => max(0, (int) ($segment['end_ms'] ?? 0)),
                'text' => $text,
            ];
        }

        return $lines;
    }

    /** `Acme kickoff 2026-10-24.docx`: the title without characters file systems refuse, and the start date. */
    public static function filename(CaptureSession $session, string $format): string
    {
        $title = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $session->displayTitle()) ?? '';
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $title = mb_substr($title === '' ? 'Transcript' : $title, 0, 80);

        return $title.' '.$session->started_at->format('Y-m-d').'.'.$format;
    }

    /** `1:05` under an hour, `1:02:05` from an hour. */
    public static function clock(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }

    /** `00:01:05,000` (SRT) or `00:01:05.000` (VTT). */
    public static function cueTime(int $ms, string $separator): string
    {
        $ms = max(0, $ms);

        return sprintf('%02d:%02d:%02d%s%03d', intdiv($ms, 3_600_000), intdiv($ms % 3_600_000, 60_000), intdiv($ms % 60_000, 1000), $separator, $ms % 1000);
    }

    /** True when the text is mostly right-to-left (Arabic script such as Urdu, or Hebrew). */
    public static function isRtl(string $text): bool
    {
        $rtl = preg_match_all('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
        $ltr = preg_match_all('/[A-Za-z\x{0900}-\x{097F}]/u', $text);

        return $rtl > 0 && $rtl >= $ltr;
    }

    /** @param list<array<string, mixed>> $lines */
    private function txt(array $lines, bool $timestamps, bool $speakers): string
    {
        return implode("\n", array_map(fn (array $l): string => trim(
            ($timestamps ? '['.self::clock($l['start_ms']).'] ' : '').($speakers ? $l['speaker'].': ' : '').$l['text']
        ), $lines))."\n";
    }

    /** @param list<array<string, mixed>> $lines */
    private function markdown(CaptureSession $session, array $lines, bool $timestamps, bool $speakers): string
    {
        $out = '# '.self::mdEscape($session->displayTitle())."\n\n*".implode(' · ', $this->facts($session))."*\n\n";
        foreach ($lines as $l) {
            $who = $speakers ? '**'.self::mdEscape($l['speaker']).'**' : '';
            $at = $timestamps ? ' `'.self::clock($l['start_ms']).'`' : '';
            $out .= trim($who.$at).($who.$at !== '' ? ': ' : '').self::mdEscape($l['text'])."\n\n";
        }
        $markers = $this->markers($session);
        if ($markers !== []) {
            $out .= "## Bookmarks\n\n";
            foreach ($markers as $m) {
                $out .= '- `'.self::clock($m->at_ms).'`'.($m->tag ? ' '.self::mdEscape($m->tag) : '').($m->note ? ' — '.self::mdEscape($m->note) : '')."\n";
            }
        }

        return rtrim($out)."\n";
    }

    /** @param list<array<string, mixed>> $lines */
    private function srt(array $lines, bool $speakers): string
    {
        $out = [];
        foreach ($lines as $i => $l) {
            $end = max($l['end_ms'], $l['start_ms'] + 500);
            $out[] = ($i + 1)."\n".self::cueTime($l['start_ms'], ',').' --> '.self::cueTime($end, ',')."\n".($speakers ? $l['speaker'].': ' : '').$l['text'];
        }

        return implode("\n\n", $out)."\n";
    }

    /** @param list<array<string, mixed>> $lines */
    private function vtt(array $lines, bool $speakers): string
    {
        $out = "WEBVTT\n";
        foreach ($lines as $l) {
            $end = max($l['end_ms'], $l['start_ms'] + 500);
            $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $l['text']);
            $voice = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $l['speaker']);
            $out .= "\n".self::cueTime($l['start_ms'], '.').' --> '.self::cueTime($end, '.')."\n".($speakers ? "<v {$voice}>" : '').$text."\n";
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $lines */
    private function json(CaptureSession $session, Transcript $transcript, array $lines): string
    {
        return (string) json_encode([
            'session' => [
                'id' => $session->id,
                'title' => $session->displayTitle(),
                'source_app' => $session->source_app->value,
                'client' => $session->client?->name,
                'started_at' => $session->started_at->toAtomString(),
                'duration_ms' => $session->duration_ms,
            ],
            'transcript' => [
                'id' => $transcript->id,
                'kind' => $transcript->kind->value,
                'provider' => $transcript->provider,
                'language' => $transcript->language,
                'edited_at' => $transcript->edited_at?->toAtomString(),
                'segments' => $lines,
            ],
            'speaker_names' => (object) $this->editor->speakerNames($session),
            'markers' => array_map(fn (SessionMarker $m): array => ['at_ms' => $m->at_ms, 'tag' => $m->tag, 'note' => $m->note], $this->markers($session)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @param list<array<string, mixed>> $lines */
    private function docx(CaptureSession $session, array $lines, bool $timestamps, bool $speakers): string
    {
        $p = static function (string $runs, bool $rtl = false, string $style = ''): string {
            $props = ($style !== '' ? '<w:pStyle w:val="'.$style.'"/>' : '').($rtl ? '<w:bidi/>' : '');

            return '<w:p>'.($props !== '' ? '<w:pPr>'.$props.'</w:pPr>' : '').$runs.'</w:p>';
        };
        $r = static function (string $text, bool $bold = false, bool $rtl = false, string $colour = ''): string {
            $props = ($bold ? '<w:b/>' : '').($colour !== '' ? '<w:color w:val="'.$colour.'"/>' : '').($rtl ? '<w:rtl/>' : '');

            return '<w:r>'.($props !== '' ? '<w:rPr>'.$props.'</w:rPr>' : '').'<w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r>';
        };
        $body = $p($r($session->displayTitle(), true), false, 'Title').$p($r(implode(' · ', $this->facts($session)), false, false, '666666'));
        foreach ($lines as $l) {
            $rtl = self::isRtl($l['text']);
            $runs = ($timestamps ? $r(self::clock($l['start_ms']).'  ', false, false, '888888') : '')
                .($speakers ? $r($l['speaker'].': ', true) : '')
                .$r($l['text'], false, $rtl);
            $body .= $p($runs, $rtl);
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr>'
            .'</w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Arial"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="120"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:sz w:val="36"/><w:szCs w:val="36"/></w:rPr></w:style>'
            .'</w:styles>';

        $path = tempnam(sys_get_temp_dir(), 'el-docx');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the document.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param list<array<string, mixed>> $lines */
    private function html(CaptureSession $session, array $lines, bool $timestamps, bool $speakers): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($lines as $l) {
            $who = $speakers ? '<span class="who spk-'.($l['channel'] === 2 ? 2 : 1).'">'.$e($l['speaker']).'</span>' : '';
            $at = $timestamps ? '<span class="at">'.$e(self::clock($l['start_ms'])).'</span>' : '';
            $rows .= '<p dir="auto">'.$at.$who.'<span class="text">'.$e($l['text']).'</span></p>'."\n";
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>'.$e($session->displayTitle()).'</title><style>'
            .'body{font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Noto Nastaliq Urdu","Noto Sans Devanagari",sans-serif;color:#111;max-width:760px;margin:32px auto;padding:0 20px}'
            .'h1{font-size:22px;margin:0 0 4px}.facts{color:#666;margin:0 0 24px}p{margin:0 0 10px}'
            .'.at{color:#888;font-variant-numeric:tabular-nums;margin-inline-end:10px}.who{font-weight:600;margin-inline-end:6px}'
            .'.spk-1{color:#5b21b6}.spk-2{color:#0e7490}@media print{body{margin:0;max-width:none}.noprint{display:none}}'
            .'</style></head><body><h1 dir="auto">'.$e($session->displayTitle()).'</h1><p class="facts">'.$e(implode(' · ', $this->facts($session))).'</p>'
            ."\n".$rows.'</body></html>';
    }

    /** @return list<string> date, duration, client and app */
    private function facts(CaptureSession $session): array
    {
        return array_values(array_filter([
            $session->started_at->copy()->setTimezone($this->settings->displayTimezone())->format('j M Y, H:i T'),
            $session->duration_ms ? self::clock($session->duration_ms) : null,
            $session->client?->name,
            $session->source_app->getLabel(),
        ]));
    }

    /** @return list<SessionMarker> */
    private function markers(CaptureSession $session): array
    {
        return $session->markers()->get()->all();
    }

    private static function mdEscape(string $text): string
    {
        return Str::of($text)->replaceMatches('/([\\\\`*_{}\[\]<>#|])/u', '\\\\$1')->toString();
    }
}
