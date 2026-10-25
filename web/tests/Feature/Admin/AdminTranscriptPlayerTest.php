<?php

declare(strict_types=1);

use App\Enums\TranscriptStatus;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/** @return list<array<string, mixed>> */
function adminCallSegments(): array
{
    return [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 4_000, 'text' => 'Hello there, this is Farah.'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 5_000, 'end_ms' => 9_000, 'text' => 'Hi, how can I help?'],
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 65_000, 'end_ms' => 70_000, 'text' => 'We spoke about the Q3 roadmap.'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 3_725_000, 'end_ms' => 3_730_000, 'text' => 'One hour later.'],
    ];
}

function adminPlayerPage($test, CaptureSession $session): string
{
    return $test->get('/admin/sessions/'.$session->id)->assertOk()->getContent();
}

/** The player's root element as a DOM node, so assertions look at structure rather than raw text. */
function adminPlayerNode(string $html): DOMElement
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    $node = (new DOMXPath($dom))->query('//div[contains(concat(" ", normalize-space(@class), " "), " el-player ")]')->item(0);
    expect($node)->not->toBeNull('the transcript player is on the page');

    return $node;
}

/** @return list<DOMElement> */
function adminPlayerRows(DOMElement $player): array
{
    $rows = [];
    foreach ((new DOMXPath($player->ownerDocument))->query('.//ol/li', $player) as $row) {
        $rows[] = $row;
    }

    return $rows;
}

function adminNodeAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        $probe = @shell_exec('node --version 2>&1');
        $available = is_string($probe) && preg_match('/^v\d+/', trim($probe)) === 1;
    }

    return $available;
}

/**
 * Evaluates the player's Alpine component in Node with the given scenario and returns what it produced.
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function adminRunPlayerScript(string $html, array $scenario = []): array
{
    expect(preg_match('/<div class="el-player"[^>]*?\sx-data="([^"]*)"/s', $html, $match))->toBe(1);
    $expression = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eld-player-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir.'/input.json', json_encode(['expression' => $expression] + $scenario));
    file_put_contents($dir.'/run.mjs', <<<'JS'
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
globalThis.setTimeout = () => 0;
globalThis.cancelAnimationFrame = () => {};
globalThis.requestAnimationFrame = () => 0;

const data = new Function('return (' + input.expression + ')')();
const out = {
  starts: data.starts,
  segments: data.segments,
  text: data.transcriptText(false),
  times: data.transcriptText(true),
};

if (input.copy) {
  const writes = [];
  const clipboard = input.copy === 'reject'
    ? { writeText: async () => { throw new Error('denied'); } }
    : { writeText: async (t) => { writes.push(t); } };
  Object.defineProperty(globalThis, 'navigator', { value: { clipboard }, configurable: true });
  const results = [];
  await data.copy(false); results.push(data.copied);
  await data.copy(true); results.push(data.copied);
  out.copy = { writes, states: results };
}

if (input.sync) {
  const rows = data.segments.map(() => {
    const attrs = new Set();
    return { attrs, setAttribute: (n) => attrs.add(n), removeAttribute: (n) => attrs.delete(n), scrollIntoView() {} };
  });
  const audio = { currentTime: 0, played: false, play() { this.played = true; } };
  data.$refs = { audio, list: { children: rows } };
  out.sync = input.sync.map((t) => {
    audio.currentTime = t;
    data.sync();
    return { t, active: data.active, marked: rows.map((r, i) => (r.attrs.has('data-active') ? i : null)).filter((i) => i !== null) };
  });
  data.seek(input.seek ?? 0);
  out.seek = { currentTime: audio.currentTime, played: audio.played };
}

if (input.desktopModule) {
  const desktop = await import(pathToFileURL(input.desktopModule).href);
  out.desktop = {
    text: desktop.transcriptText(data.segments, false),
    times: desktop.transcriptText(data.segments, true),
  };
}

console.log(JSON.stringify(out));
JS);

    $process = proc_open(['node', '--no-warnings', $dir.'/run.mjs', $dir.'/input.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $status = proc_close($process);
    @unlink($dir.'/input.json');
    @unlink($dir.'/run.mjs');
    @rmdir($dir);

    expect($status)->toBe(0, "node failed: {$stderr}");

    return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
}

// --- Structure ------------------------------------------------------------------------------------------------

it('lists every segment with a time, a speaker label and the text', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    $rows = adminPlayerRows(adminPlayerNode(adminPlayerPage($this, $session)));

    expect($rows)->toHaveCount(4);
    $cells = array_map(fn (DOMElement $row): array => array_map(fn ($span) => trim($span->textContent), iterator_to_array($row->getElementsByTagName('span'))), $rows);
    expect($cells[0])->toBe(['00:00', 'Me', 'Hello there, this is Farah.'])
        ->and($cells[1])->toBe(['00:05', 'Client', 'Hi, how can I help?'])
        ->and($cells[2])->toBe(['01:05', 'Me', 'We spoke about the Q3 roadmap.'])
        ->and($cells[3])->toBe(['1:02:05', 'Client', 'One hour later.']);
});

it('shows times as mm:ss under an hour and h:mm:ss from an hour', function (int $ms, string $expected) {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => $ms, 'end_ms' => $ms + 1, 'text' => 'x']]);

    $rows = adminPlayerRows(adminPlayerNode(adminPlayerPage($this, $session)));

    expect(trim($rows[0]->getElementsByTagName('span')->item(0)->textContent))->toBe($expected);
})->with([
    'start' => [0, '00:00'],
    'under a second' => [999, '00:00'],
    'one minute' => [60_000, '01:00'],
    'just under an hour' => [3_599_999, '59:59'],
    'exactly an hour' => [3_600_000, '1:00:00'],
    'ten hours' => [36_000_000, '10:00:00'],
]);

it('colours Me and Client differently by channel and falls back to "Speaker"', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'mine'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1000, 'end_ms' => 2, 'text' => 'theirs'],
        ['channel' => 1, 'start_ms' => 2000, 'end_ms' => 3, 'text' => 'unlabelled'],
    ]);

    $rows = adminPlayerRows(adminPlayerNode(adminPlayerPage($this, $session)));
    $classes = array_map(fn (DOMElement $row): string => $row->getElementsByTagName('span')->item(1)->getAttribute('class'), $rows);
    $labels = array_map(fn (DOMElement $row): string => trim($row->getElementsByTagName('span')->item(1)->textContent), $rows);

    expect($classes)->toBe(['el-spk el-spk-1', 'el-spk el-spk-2', 'el-spk el-spk-1'])
        ->and($labels)->toBe(['Me', 'Client', 'Speaker']);
});

it('plays the decrypted recording from the admin media route', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $recording = $this->readyRecording($session);

    $player = adminPlayerNode(adminPlayerPage($this, $session));
    $audio = $player->getElementsByTagName('audio')->item(0);

    expect($audio)->not->toBeNull()
        ->and($audio->getAttribute('src'))->toBe(route('admin.recordings.stream', ['recording' => $recording->id]))
        ->and($audio->hasAttribute('controls'))->toBeTrue()
        ->and($audio->getAttribute('preload'))->toBe('metadata');
});

it('explains when there is no audio to play', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    $player = adminPlayerNode(adminPlayerPage($this, $session));

    expect($player->getElementsByTagName('audio')->length)->toBe(0)
        ->and($player->textContent)->toContain('Audio is not available (still uploading, deleted, or retention expired).');
});

it('says "No transcript yet" and offers no copy buttons when there are no segments', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id]);

    $html = adminPlayerPage($this, $session);
    $player = adminPlayerNode($html);

    expect($player->textContent)->toContain('No transcript yet.')
        ->and($player->getElementsByTagName('button')->length)->toBe(0)
        ->and(adminPlayerRows($player))->toBe([]);
});

it('labels a final transcript and a live-caption transcript differently', function () {
    $admin = $this->signInAsAdmin();
    $final = $this->sessionWithTranscript($admin, adminCallSegments());
    $live = $this->sessionWithTranscript($admin, adminCallSegments(), kind: 'live', transcript: ['status' => TranscriptStatus::Completed]);

    expect(adminPlayerNode(adminPlayerPage($this, $final))->textContent)->toContain('Final transcript')->not->toContain('Live captions');
    expect(adminPlayerNode(adminPlayerPage($this, $live))->textContent)->toContain('Live captions (final transcript pending)');
});

it('prefers the final transcript, but falls back to live captions while the final one has no text', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'LIVE caption text']], kind: 'live');
    Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => 'final', 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing, 'segments' => []]);

    $text = adminPlayerNode(adminPlayerPage($this, $session))->textContent;
    expect($text)->toContain('LIVE caption text')->toContain('processing');

    Transcript::query()->where('capture_session_id', $session->id)->where('kind', 'final')->update([
        'status' => TranscriptStatus::Completed->value,
        'segments' => json_encode([['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'FINAL transcript text']]),
    ]);
    $text = adminPlayerNode(adminPlayerPage($this, $session))->textContent;
    expect($text)->toContain('FINAL transcript text')->not->toContain('LIVE caption text');
});

it('shows the transcript status while it is not finished', function (TranscriptStatus $status, string $label) {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments(), transcript: ['status' => $status]);

    expect(adminPlayerNode(adminPlayerPage($this, $session))->textContent)->toContain($label);
})->with([
    [TranscriptStatus::Pending, 'pending'],
    [TranscriptStatus::Submitted, 'submitted'],
    [TranscriptStatus::Processing, 'processing'],
    [TranscriptStatus::AwaitingDevice, 'awaiting device'],
    [TranscriptStatus::Failed, 'failed'],
]);

// --- Copy controls --------------------------------------------------------------------------------------------

it('has Copy text and Copy with timestamps buttons wired to the copy handler', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    $player = adminPlayerNode(adminPlayerPage($this, $session));
    $buttons = iterator_to_array($player->getElementsByTagName('button'));

    expect($buttons)->toHaveCount(2)
        ->and($buttons[0]->getAttribute('x-on:click'))->toBe('copy(false)')
        ->and($buttons[0]->getAttribute('x-text'))->toContain('Copy text')
        ->and($buttons[1]->getAttribute('x-on:click'))->toBe('copy(true)')
        ->and($buttons[1]->getAttribute('x-text'))->toContain('Copy with timestamps')
        ->and($buttons[0]->getAttribute('type'))->toBe('button')
        ->and($buttons[1]->getAttribute('type'))->toBe('button');
});

it('carries every segment\'s speaker, start time and text to the browser', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    if (! adminNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to evaluate the player script.');
    }

    $result = adminRunPlayerScript(adminPlayerPage($this, $session));

    expect($result['starts'])->toEqual([0, 5, 65, 3725])
        ->and($result['segments'])->toBe([
            ['speaker' => 'Me', 'start_ms' => 0, 'text' => 'Hello there, this is Farah.'],
            ['speaker' => 'Client', 'start_ms' => 5000, 'text' => 'Hi, how can I help?'],
            ['speaker' => 'Me', 'start_ms' => 65000, 'text' => 'We spoke about the Q3 roadmap.'],
            ['speaker' => 'Client', 'start_ms' => 3725000, 'text' => 'One hour later.'],
        ]);
});

it('copies "Speaker: text" lines without timestamps', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    if (! adminNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to evaluate the player script.');
    }

    $result = adminRunPlayerScript(adminPlayerPage($this, $session));

    expect($result['text'])->toBe(implode("\n", [
        'Me: Hello there, this is Farah.',
        'Client: Hi, how can I help?',
        'Me: We spoke about the Q3 roadmap.',
        'Client: One hour later.',
    ]));
});

it('copies "[m:ss] Speaker: text" lines with timestamps, using h:mm:ss from an hour', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    if (! adminNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to evaluate the player script.');
    }

    $result = adminRunPlayerScript(adminPlayerPage($this, $session));

    expect($result['times'])->toBe(implode("\n", [
        '[0:00] Me: Hello there, this is Farah.',
        '[0:05] Client: Hi, how can I help?',
        '[1:05] Me: We spoke about the Q3 roadmap.',
        '[1:02:05] Client: One hour later.',
    ]));
});

it('leaves the speaker out when only one person speaks', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [
        ['channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'A short voice note.'],
        ['channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => 'Second sentence.'],
    ]);
    if (! adminNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to evaluate the player script.');
    }

    $result = adminRunPlayerScript(adminPlayerPage($this, $session));

    expect($result['text'])->toBe("A short voice note.\nSecond sentence.")
        ->and($result['times'])->toBe("[0:00] A short voice note.\n[0:01] Second sentence.");
});

it('produces exactly the same text as the desktop app\'s copy function', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $module = realpath(base_path('../desktop/src/lib/format.ts'));
    if (! adminNodeAvailable() || $module === false) {
        $this->markTestSkipped('Node.js or the desktop sources are not available.');
    }

    $result = adminRunPlayerScript(adminPlayerPage($this, $session), ['desktopModule' => $module]);

    expect($result['text'])->toBe($result['desktop']['text'])
        ->and($result['times'])->toBe($result['desktop']['times']);
});

it('writes the chosen text to the clipboard and reports Copied or Copy failed', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    if (! adminNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to evaluate the player script.');
    }
    $html = adminPlayerPage($this, $session);

    $ok = adminRunPlayerScript($html, ['copy' => 'ok']);
    expect($ok['copy']['writes'])->toBe([$ok['text'], $ok['times']])
        ->and($ok['copy']['states'])->toBe(['text', 'times']);

    $denied = adminRunPlayerScript($html, ['copy' => 'reject']);
    expect($denied['copy']['states'])->toBe(['failed', 'failed']);
});

it('highlights the segment being played and seeks when a line is clicked', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    if (! adminNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to evaluate the player script.');
    }

    // starts are [0, 5, 65, 3725] seconds
    $result = adminRunPlayerScript(adminPlayerPage($this, $session), ['sync' => [0, 4.99, 5, 30, 64.9, 65, 4000], 'seek' => 2]);

    $active = array_map(fn (array $step): int => $step['active'], $result['sync']);
    expect($active)->toBe([0, 0, 1, 1, 1, 2, 3]);
    foreach ($result['sync'] as $step) {
        expect($step['marked'])->toBe([$step['active']]); // exactly one row is highlighted at a time
    }
    expect($result['seek'])->toBe(['currentTime' => 65, 'played' => true]);
});

it('highlights nothing before the first segment starts', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 2_000, 'end_ms' => 3_000, 'text' => 'late start'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 2_000, 'end_ms' => 3_000, 'text' => 'same start'],
    ]);
    if (! adminNodeAvailable()) {
        $this->markTestSkipped('Node.js is not available to evaluate the player script.');
    }

    $result = adminRunPlayerScript(adminPlayerPage($this, $session), ['sync' => [0, 1.9, 2]]);

    expect(array_map(fn (array $s): int => $s['active'], $result['sync']))->toBe([-1, -1, 1]);
});

it('keeps hostile text inside the data, never in the markup or the script', function () {
    $admin = $this->signInAsAdmin();
    $payloads = [
        '<script>alert("xss")</script>',
        '"><img src=x onerror=alert(1)>',
        "');alert(1);//",
        '</script><svg onload=alert(1)>',
        '{{ 7*7 }} ${7*7} <b>bold</b> & \' " \\',
    ];
    $segments = array_map(fn (string $text, int $i): array => ['channel' => ($i % 2) + 1, 'speaker' => $i === 0 ? '<i>Mallory</i>' : 'Client', 'start_ms' => $i * 1000, 'end_ms' => $i * 1000 + 500, 'text' => $text], $payloads, array_keys($payloads));
    $session = $this->sessionWithTranscript($admin, $segments);
    $html = adminPlayerPage($this, $session);
    $player = adminPlayerNode($html);

    foreach (['script', 'img', 'svg', 'iframe', 'object', 'embed', 'b', 'i'] as $tag) {
        expect($player->getElementsByTagName($tag)->length)->toBe(0, "unexpected <{$tag}> inside the player");
    }
    $xpath = new DOMXPath($player->ownerDocument);
    foreach ($xpath->query('.//*[@*[starts-with(name(), "on")]]', $player) as $element) {
        $this->fail('Inline event handler found on <'.$element->nodeName.'>');
    }
    $rows = adminPlayerRows($player);
    foreach ($payloads as $i => $payload) {
        expect($rows[$i]->getElementsByTagName('span')->item(2)->textContent)->toBe($payload);
    }
    expect($rows[0]->getElementsByTagName('span')->item(1)->textContent)->toBe('<i>Mallory</i>');

    if (adminNodeAvailable()) {
        $result = adminRunPlayerScript($html);
        expect(array_column($result['segments'], 'text'))->toBe($payloads);
    }
});

it('exposes the player data through the entry class', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $recording = $this->readyRecording($session);

    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->instance();
    $schema = Schema::make($page)->record($session->fresh())->components([TranscriptPlayerEntry::make('player')]);
    $data = $schema->getComponent('player')->getPlayerData();

    expect($data['audio_url'])->toBe(route('admin.recordings.stream', ['recording' => $recording->id]))
        ->and($data['kind'])->toBe('final')
        ->and($data['status'])->toBe('completed')
        ->and($data['segments'])->toHaveCount(4)
        ->and($data['text'])->toContain('Hello there');
});
