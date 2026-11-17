<?php

declare(strict_types=1);

namespace App\Services\Screens;

use App\Enums\RecordingRole;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Http\Controllers\AdminMediaController;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Screen;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\SettingsRepository;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;
use App\Stt\LanguageChoice;
use App\Support\UnsureMarkers;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The meeting pack (v1.5, docs/API.md "Meeting pack"): the transcript in a Show as view with each screen moment placed
 * at its time as "[Screen 7 at 14:32]" and its description. `html` is one self-contained page to print (pictures as
 * data URIs, Urdu in Noto Nastaliq, a title page); `zip` is transcript.md with Markdown images, the images/ folder and,
 * when asked, the audio. Both are written to a private temp file the caller sends and deletes; the audio is never
 * held in memory.
 */
final class MeetingPack
{
    /** `text` (since v1.8 P4.4): Copy for AI, the transcript with the screens' markers and a line explaining them. */
    public const FORMATS = ['html', 'zip', 'text'];

    public const VIEWS = ['roman', 'english', 'urdu', 'original'];

    /**
     * A printed pack stays under about this many pages (since v1.8 P4.4): a longer meeting comes in parts (`part=`),
     * each with its own title page. Pages are estimated: 45 lines of about 90 characters a page, half a page a screen.
     */
    public const PAGES_PER_PART = 100;

    private const LINES_PER_PAGE = 45;

    private const CHARS_PER_LINE = 90;

    private const PAGES_PER_SCREEN = 0.5;

    /** Up to this many bytes of pictures the page embeds them as they were sent; past it, every screen as its thumbnail. */
    public const FULL_PICTURES_BYTES = 20 * 1024 * 1024;

    private const ARABIC_SCRIPT_RANGE = 'U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC';

    public function __construct(
        private readonly TranscriptExporter $exporter,
        private readonly ScreenStore $screens,
        private readonly ChunkedUploadService $uploads,
        private readonly SettingsRepository $settings,
    ) {}

    /** The console's bar above the page (hidden in print): how to keep a PDF, and the Print button. */
    private const PRINT_BAR = '<div class="noprint" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 24px;padding:10px 14px;border:1px solid #ddd;border-radius:10px;font-size:14px;color:#444">'
        .'<span>To keep a PDF, choose “Save as PDF” as the printer.</span>'
        .'<button type="button" onclick="window.print()" style="font:inherit;padding:6px 14px;border:0;border-radius:8px;background:#3e63dd;color:#fff;cursor:pointer">Print or save as PDF</button></div>';

    /**
     * Writes the pack. `409 nothing_to_export` while the recording has no finished final transcript. `$printBar`
     * (the console) puts a Print button above the page.
     *
     * @return array{path: string, name: string, mime: string, part: int, parts: int}
     */
    public function build(CaptureSession $session, string $format, ?string $view = null, bool $withAudio = false, bool $printBar = false, int $part = 1): array
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new RuntimeException("Unknown meeting pack format {$format}.");
        }
        $transcript = $session->transcripts()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)->first();
        if (! $transcript instanceof Transcript) {
            throw new ApiError(409, 'nothing_to_export', 'This recording has no finished transcript yet.');
        }
        $transcript->setRelation('captureSession', $session);
        $view = $view === 'original' ? null : $view;
        $lines = $this->exporter->lines($transcript, $session, $view);
        // A view that is not made yet shows the original words.
        $shown = $view !== null && app(TranscriptViews::class)->textsFor($transcript, $view) !== null ? $view : null;
        $screens = ScreenStore::ordered($session->id)->get()->values();
        // The printed pack in parts of about 100 pages (the folder and Copy for AI are whole).
        $parts = $format === 'html' ? self::parts(self::timeline($lines, $screens)) : [self::timeline($lines, $screens)];
        if ($part < 1 || $part > count($parts)) {
            throw new ApiError(404, 'not_found', 'This meeting pack has '.count($parts).(count($parts) === 1 ? ' part.' : ' parts.'));
        }
        $path = $this->uploads->tempPath('meeting-pack');
        try {
            match ($format) {
                'html' => $this->writeHtml($path, $session, $transcript, $shown, $lines, $screens, $printBar, $parts[$part - 1], $part, count($parts)),
                'text' => $this->writeText($path, $session, $lines, $screens),
                default => $this->writeZip($path, $session, $lines, $screens, $withAudio),
            };
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $name = TranscriptExporter::filename($session, $format === 'text' ? 'txt' : $format);
        if (count($parts) > 1) {
            $name = (string) preg_replace('/\.html$/', ' (part '.$part.' of '.count($parts).').html', $name);
        }

        return [
            'part' => $part,
            'parts' => count($parts),
            'path' => $path,
            'name' => $name,
            'mime' => match ($format) {
                'html' => 'text/html; charset=utf-8',
                'text' => 'text/plain; charset=utf-8',
                default => 'application/zip',
            },
        ];
    }

    /**
     * "[Screen 7 at 14:32 — Orders page, client points at Submit]" without the brackets: the screen's number, its moment
     * and (since v1.8 P4.4) its description.
     */
    public static function label(int $number, int $atMs, ?string $description = null): string
    {
        $description = trim((string) preg_replace('/\s+/u', ' ', (string) $description));

        return 'Screen '.$number.' at '.TranscriptExporter::clock($atMs).($description !== '' ? ' — '.$description : '');
    }

    /** `screens/S07_14-32_orders-page.webp` (since v1.8 P4.2, ScreenAssets::fileName): where the folder keeps it. */
    public static function imageName(Screen $screen, int $number, int $count): string
    {
        return 'screens/'.ScreenAssets::fileName($screen, $number, $count);
    }

    /** What Copy for AI says first: what the markers mean. */
    public const AI_NOTE = 'Note: a line like [Screen 7 at 14:32 — what it shows] marks what was on the shared screen at that moment of the recording; the pictures are in the meeting folder (ZIP), named by their number and time.';

    /**
     * The lines and the screens in time order: each screen comes before the first line that starts after its moment.
     *
     * @param  list<array{index: int, speaker: string, label: string, channel: int, start_ms: int, end_ms: int, text: string}>  $lines
     * @param  Collection<int, Screen>  $screens  in time order
     * @return list<array{0: 'line', 1: array<string, mixed>}|array{0: 'screen', 1: Screen, 2: int}>
     */
    public static function timeline(array $lines, Collection $screens): array
    {
        $out = [];
        $next = 0;
        $count = $screens->count();
        foreach ($lines as $line) {
            while ($next < $count && $screens[$next]->at_ms < $line['start_ms']) {
                $out[] = ['screen', $screens[$next], $next + 1];
                $next++;
            }
            $out[] = ['line', $line];
        }
        for (; $next < $count; $next++) {
            $out[] = ['screen', $screens[$next], $next + 1];
        }

        return $out;
    }

    /** The console's bar (hidden in print): Print, and since v1.8 P4.4 the other parts of a long meeting. */
    private static function printBar(CaptureSession $session, ?string $view, int $part, int $parts): string
    {
        if ($parts === 1) {
            return self::PRINT_BAR;
        }
        $link = fn (int $n, string $label): string => '<a href="'.htmlspecialchars(route('admin.sessions.pack', array_filter(['session' => $session->id, 'format' => 'html', 'view' => $view, 'part' => $n])), ENT_QUOTES).'" style="color:#3e63dd">'.$label.'</a>';
        $nav = '<span style="display:flex;gap:12px;align-items:center">Part '.$part.' of '.$parts
            .($part > 1 ? ' · '.$link($part - 1, '← Part '.($part - 1)) : '').($part < $parts ? ' · '.$link($part + 1, 'Part '.($part + 1).' →') : '').'</span>';

        return str_replace('<span>To keep a PDF', $nav.'<span>To keep a PDF', self::PRINT_BAR);
    }

    /**
     * The timeline in parts of about 100 printed pages, at an item's edge (a screen stays with its moment).
     *
     * @param  list<array{0: 'line', 1: array<string, mixed>}|array{0: 'screen', 1: Screen, 2: int}>  $timeline
     * @return non-empty-list<list<array{0: 'line', 1: array<string, mixed>}|array{0: 'screen', 1: Screen, 2: int}>>
     */
    public static function parts(array $timeline): array
    {
        $parts = [];
        $current = [];
        $pages = 1.0; // the title page
        foreach ($timeline as $item) {
            $size = $item[0] === 'screen'
                ? self::PAGES_PER_SCREEN
                : (ceil(max(1, mb_strlen((string) $item[1]['text']) + mb_strlen((string) $item[1]['speaker']) + 8) / self::CHARS_PER_LINE) + 0.4) / self::LINES_PER_PAGE;
            if ($current !== [] && $pages + $size > self::PAGES_PER_PART) {
                $parts[] = $current;
                $current = [];
                $pages = 1.0;
            }
            $current[] = $item;
            $pages += $size;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * @param  string|null  $view  the Show as view the lines are in, null for the original words
     * @param  list<array{index: int, speaker: string, label: string, channel: int, start_ms: int, end_ms: int, text: string}>  $lines
     * @param  Collection<int, Screen>  $screens
     * @param  list<array{0: 'line', 1: array<string, mixed>}|array{0: 'screen', 1: Screen, 2: int}>  $items  this part's
     */
    private function writeHtml(string $path, CaptureSession $session, Transcript $transcript, ?string $view, array $lines, Collection $screens, bool $printBar, array $items, int $part, int $parts): void
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not write the meeting pack.');
        }
        try {
            $rtl = array_filter($lines, fn (array $l): bool => TranscriptExporter::isRtl($l['text'])) !== [];
            // An Arabic call shown as itself is set in Naskh as on the console; Urdu (an Arabic call's Urdu view too, and
            // any other Arabic-script line) in Nastaliq.
            $arabic = $view === null && LanguageChoice::normalize($transcript->language) === 'ar';
            $font = $arabic ? 'Noto Naskh Arabic' : 'Noto Nastaliq Urdu';
            $face = '';
            $file = public_path($arabic ? 'fonts/noto-naskh-arabic-arabic.woff2' : 'fonts/noto-nastaliq-urdu-arabic.woff2');
            if ($rtl && is_file($file)) {
                $face = '@font-face{font-family:"'.$font.'";src:url(data:font/woff2;base64,'.base64_encode((string) file_get_contents($file)).') format("woff2");'
                    .'font-weight:400 700;font-display:block;unicode-range:'.self::ARABIC_SCRIPT_RANGE.'}';
            }
            $title = $session->displayTitle();
            fwrite($out, '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                .'<meta name="robots" content="noindex"><title>'.$e($title).'</title><style>'.$face
                .'body{font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans",sans-serif;color:#111;max-width:760px;margin:32px auto;padding:0 20px}'
                .'.cover{min-height:70vh;display:flex;flex-direction:column;justify-content:center;break-after:page;page-break-after:always}'
                .'.kicker{text-transform:uppercase;letter-spacing:.08em;font-size:12px;color:#666;margin:0 0 8px}'
                .'.cover h1{font-size:30px;line-height:1.25;margin:0 0 24px}'
                .'.facts{display:grid;grid-template-columns:max-content 1fr;gap:6px 18px;margin:0}.facts dt{color:#666}.facts dd{margin:0}'
                .'.note{color:#666;margin:24px 0 0}p.line{margin:0 0 10px}'
                .'.at{color:#888;font-variant-numeric:tabular-nums;margin-inline-end:10px}.who{font-weight:600;margin-inline-end:6px}'
                .'.spk-1{color:#3a5bc7}.spk-2{color:#007563}'
                .'p.line[dir=rtl] .text{font-family:"'.$font.'",serif;font-size:18px;line-height:2.1}'
                .'figure.screen{margin:18px 0 22px;break-inside:avoid;page-break-inside:avoid}'
                .'figure.screen img{display:block;max-width:100%;height:auto;border:1px solid #ddd;border-radius:8px}'
                .'figcaption{font-size:13px;color:#444;margin:0 0 8px}.label{font-weight:600;color:#111}'
                .'@page{margin:16mm}@media print{body{margin:0;max-width:none}.noprint{display:none}.cover{min-height:calc(100vh - 32mm)}}'
                .'</style></head><body>'.($printBar ? self::printBar($session, $view, $part, $parts) : ''));

            $facts = $this->facts($session, $lines, $screens->count());
            fwrite($out, '<section class="cover"><p class="kicker">Meeting pack'.($parts > 1 ? ' · Part '.$part.' of '.$parts : '').'</p><h1 dir="auto">'.$e($title).'</h1><dl class="facts">');
            foreach ($facts as $name => $value) {
                fwrite($out, '<dt>'.$e($name).'</dt><dd dir="auto">'.$e($value).'</dd>');
            }
            fwrite($out, '</dl>'.(($note = $this->noteLine($lines)) !== null ? '<p class="note">'.$e($note).'</p>' : '').'</section><main>'."\n");

            $thumbs = (int) $screens->sum('bytes') > self::FULL_PICTURES_BYTES;
            foreach ($items as $item) {
                if ($item[0] === 'line') {
                    $l = $item[1];
                    fwrite($out, '<p class="line" dir="'.(TranscriptExporter::isRtl($l['text']) ? 'rtl' : 'auto').'">'
                        .'<span class="at">'.$e(TranscriptExporter::clock($l['start_ms'])).'</span>'
                        .'<span class="who spk-'.($l['channel'] === 2 ? 2 : 1).'">'.$e($l['speaker']).'</span><span class="text">'.$e($l['text']).'</span></p>'."\n");

                    continue;
                }
                [, $screen, $number] = $item;
                $label = self::label($number, $screen->at_ms, $screen->description);
                $picture = $this->screens->picture($screen, $thumbs);
                fwrite($out, '<figure class="screen" id="screen-'.$number.'"><figcaption><span class="label" dir="auto">['.$e($label).']</span></figcaption>');
                if ($picture !== null) {
                    fwrite($out, '<img src="data:'.($thumbs ? 'image/webp' : $screen->mime).';base64,');
                    fwrite($out, base64_encode($picture));
                    fwrite($out, '" alt="'.$e($label).'">');
                }
                fwrite($out, '</figure>'."\n");
            }
            fwrite($out, '</main></body></html>');
        } finally {
            fclose($out);
        }
    }

    /**
     * @param  list<array{index: int, speaker: string, label: string, channel: int, start_ms: int, end_ms: int, text: string}>  $lines
     * @param  Collection<int, Screen>  $screens
     */
    private function writeZip(string $path, CaptureSession $session, array $lines, Collection $screens, bool $withAudio): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip file.');
        }
        // Pictures and audio are added from files, read when the zip closes; they are deleted right after.
        $temp = [];
        try {
            $md = '# '.TranscriptExporter::mdEscape($session->displayTitle())."\n\n";
            foreach ($this->facts($session, $lines, $screens->count()) as $name => $value) {
                $md .= '- **'.$name.':** '.TranscriptExporter::mdEscape($value)."\n";
            }
            $md .= "\n";
            if (($note = $this->noteLine($lines)) !== null) {
                $md .= '> '.TranscriptExporter::mdEscape($note)."\n\n";
            }
            $count = $screens->count();
            foreach (self::timeline($lines, $screens) as $item) {
                if ($item[0] === 'line') {
                    $l = $item[1];
                    $md .= '**'.TranscriptExporter::mdEscape($l['speaker']).'** `'.TranscriptExporter::clock($l['start_ms']).'`: '.TranscriptExporter::mdEscape($l['text'])."\n\n";

                    continue;
                }
                [, $screen, $number] = $item;
                // Since v1.8 P4.4: "[Screen 7 at 14:32 — Orders page]", then the picture from screens/.
                $short = self::label($number, $screen->at_ms);
                $name = self::imageName($screen, $number, $count);
                $md .= '**\['.TranscriptExporter::mdEscape(self::label($number, $screen->at_ms, $screen->description)).'\]**'."\n";
                if ($this->addPicture($zip, $screen, $name, $temp)) {
                    $md .= '!['.$short.'](<'.$name.'> "'.$short.'")'."\n";
                }
                $md .= "\n";
            }
            $zip->addFromString('transcript.md', rtrim($md)."\n");
            if ($count > 0) {
                $zip->addFromString('screens.md', $this->screensTable($session, $screens, $lines));
            }
            if ($withAudio) {
                $primary = $session->recordings()->where('role', RecordingRole::Primary->value)->first();
                if ($primary instanceof Recording && $primary->isReady()) {
                    $audio = $this->uploads->decryptToTemp($primary);
                    $temp[] = $audio;
                    $entry = pathinfo(TranscriptExporter::filename($session, 'txt'), PATHINFO_FILENAME).'.'.AdminMediaController::extensionFor($primary->mime);
                    $zip->addFile($audio, $entry);
                    // Stored as it is: audio is already compressed, and packing it again would run inside the request.
                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('Could not write the zip file.');
            }
        } finally {
            foreach ($temp as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Adds [$screen]'s picture to [$zip] as [$name]; false when it is gone. The temp file is deleted with the others.
     *
     * @param  list<string>  $temp
     */
    private function addPicture(ZipArchive $zip, Screen $screen, string $name, array &$temp): bool
    {
        $picture = $this->screens->picture($screen);
        if ($picture === null) {
            return false;
        }
        $file = $this->uploads->tempPath('pack-image');
        file_put_contents($file, $picture);
        $temp[] = $file;
        $zip->addFile($file, $name);
        $zip->setCompressionName($name, ZipArchive::CM_STORE);

        return true;
    }

    /**
     * screens.md (since v1.8 P4.4): one row a screen, its number, time, file, description and what was said around it.
     *
     * @param  Collection<int, Screen>  $screens
     * @param  list<array{speaker: string, start_ms: int, end_ms: int, text: string}>  $lines
     */
    private function screensTable(CaptureSession $session, Collection $screens, array $lines): string
    {
        $cell = fn (?string $v): string => $v === null || trim($v) === '' ? '—' : str_replace(["\r\n", "\n", "\r"], ' / ', TranscriptExporter::mdEscape(trim($v)));
        $out = ['# Screens of '.TranscriptExporter::mdEscape($session->displayTitle()), '',
            '| # | Time | File | Description | What was said |', '|---|---|---|---|---|'];
        foreach ($screens as $i => $screen) {
            $name = self::imageName($screen, $i + 1, $screens->count());
            $out[] = '| '.($i + 1).' | '.TranscriptExporter::clock($screen->at_ms).' | [`'.basename($name).'`](<'.$name.'>) | '
                .$cell($screen->description).' | '.$cell(ScreenAssets::said($screen, $lines)).' |';
        }

        return implode("\n", $out)."\n";
    }

    /**
     * Copy for AI (since v1.8 P4.4): a line saying what the markers mean, the facts, then the transcript with each
     * screen as "[Screen 7 at 14:32 — Orders page]" at its moment, as plain text.
     *
     * @param  list<array{index: int, speaker: string, label: string, channel: int, start_ms: int, end_ms: int, text: string}>  $lines
     * @param  Collection<int, Screen>  $screens
     */
    private function writeText(string $path, CaptureSession $session, array $lines, Collection $screens): void
    {
        $out = [];
        if ($screens->isNotEmpty()) {
            $out[] = self::AI_NOTE;
            $out[] = '';
        }
        $out[] = $session->displayTitle();
        foreach ($this->facts($session, $lines, $screens->count()) as $name => $value) {
            $out[] = $name.': '.$value;
        }
        if (($note = $this->noteLine($lines)) !== null) {
            $out[] = $note;
        }
        $out[] = '';
        foreach (self::timeline($lines, $screens) as $item) {
            $out[] = $item[0] === 'line'
                ? '['.TranscriptExporter::clock($item[1]['start_ms']).'] '.($item[1]['speaker'] !== '' ? $item[1]['speaker'].': ' : '').$item[1]['text']
                : '['.self::label($item[2], $item[1]->at_ms, $item[1]->description).']';
        }
        if (file_put_contents($path, implode("\n", $out)."\n") === false) {
            throw new RuntimeException('Could not write the meeting pack.');
        }
    }

    /**
     * The title page: date, length, people, project and how many screens.
     *
     * @param  list<array{speaker: string}>  $lines
     * @return array<string, string>
     */
    private function facts(CaptureSession $session, array $lines, int $screens): array
    {
        $people = array_values(array_unique(array_filter(array_map(fn (array $l): string => trim($l['speaker']), $lines))));
        if ($session->caller_name !== null && $session->caller_name !== '' && ! in_array($session->caller_name, $people, true)) {
            $people[] = $session->caller_name;
        }

        return array_filter([
            'Date' => $session->started_at->copy()->setTimezone($this->settings->displayTimezone())->format('j M Y, H:i T'),
            'Length' => $session->duration_ms ? TranscriptExporter::clock($session->duration_ms) : '',
            'People' => implode(', ', $people),
            'Project' => $session->client_id !== null ? (string) $session->client?->name : '',
            'App' => $session->source_app->getLabel(),
            'Screens' => $screens > 0 ? (string) $screens : '',
        ], fn (string $v): bool => $v !== '');
    }

    /** @param  list<array{text: string}>  $lines */
    private function noteLine(array $lines): ?string
    {
        $markers = UnsureMarkers::settings($this->settings);

        return $markers['note'] && $markers['style'] !== 'off' && array_filter($lines, fn (array $l): bool => UnsureMarkers::hasMarkers($l['text'])) !== []
            ? UnsureMarkers::note($markers['style']) : null;
    }
}
