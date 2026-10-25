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

/*
 * The console transcript player (v1.1): speaker blocks, word highlight, speeds, skip silence, follow, the keys
 * Space / J / K / L / ← / →, the activity timeline with bookmarks, copy, and inline editing. The Alpine component is
 * evaluated in Node so its behaviour is tested, not only its markup.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/** @return list<array<string, mixed>> */
function adminCallSegments(): array
{
    return [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 4_000, 'text' => 'Hello there, this is Farah.',
            'words' => [['text' => 'Hello', 'start_ms' => 0, 'end_ms' => 400], ['text' => 'there,', 'start_ms' => 450, 'end_ms' => 900]]],
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
globalThis.$wire = { saveSegment: async (i, text) => input.save === 'refuse' ? { error: 'Only a finished transcript can be edited.' } : { ...input.saved, saved: [i, text] } };

const data = new Function('return (' + input.expression + ')')();
data.$nextTick = (f) => f();
const out = {
  d: data.d,
  text: data.transcriptText(false),
  times: data.transcriptText(true),
  blocks: data.blocks().map((b) => ({ speaker: b.speaker, items: b.items })),
  pct: [data.pct(0), data.pct(data.d.duration_ms / 2), data.pct(data.d.duration_ms * 2)],
  clock: [data.clock(0), data.clock(65000), data.clock(3725000)],
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

const audio = { currentTime: 0, paused: true, playbackRate: 1, play() { this.paused = false; }, pause() { this.paused = true; } };
data.$refs = { audio, list: { querySelector: () => ({ scrollIntoView() {} }) } };

if (input.sync) {
  out.sync = input.sync.map((t) => { audio.currentTime = t; data.sync(); return { t, active: data.active, word: data.word }; });
  data.seekTo(input.seekTo ?? 0);
  out.seek = { currentTime: audio.currentTime, playing: !audio.paused };
}

if (input.keys) {
  audio.currentTime = 30; audio.paused = true;
  out.keys = input.keys.map((k) => {
    let prevented = false;
    data.onKey({ key: k.key, code: k.code || '', target: { tagName: k.tag }, preventDefault() { prevented = true; } });
    return { prevented, paused: audio.paused, time: audio.currentTime };
  });
}

if (input.skip) {
  data.skipSilence = true; audio.paused = false;
  out.skip = input.skip.map((t) => { audio.currentTime = t; data.tick(); return audio.currentTime; });
}

if (input.rate) { data.setRate(input.rate); out.rate = audio.playbackRate; }

if (input.save) {
  await data.save(0, input.save === 'refuse' ? 'changed' : input.text);
  out.save = { error: data.error, d: data.d, saving: data.saving };
}

if (input.desktopModule) {
  const desktop = await import(pathToFileURL(input.desktopModule).href);
  const named = data.d.segments.map((s) => ({ ...s, speaker: data.name(s) }));
  out.desktop = { text: desktop.transcriptText(named, false), times: desktop.transcriptText(named, true) };
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

function adminNeedsNode(object $test): void
{
    if (! adminNodeAvailable()) {
        $test->markTestSkipped('Node.js is not available to evaluate the player script.');
    }
}

// --- Data ------------------------------------------------------------------------------------------------------

it('exposes the player data through the entry class', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $session->update(['speaker_names' => ['Client' => 'Jane'], 'duration_ms' => 3_730_000]);
    $recording = $this->readyRecording($session);

    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->instance();
    $schema = Schema::make($page)->record($session->fresh())->components([TranscriptPlayerEntry::make('player')]);
    $data = $schema->getComponent('player')->getPlayerData();

    expect($data['audio_url'])->toBe(route('admin.recordings.stream', ['recording' => $recording->id]))
        ->and($data['kind'])->toBe('final')
        ->and($data['status'])->toBe('completed')
        ->and($data['editable'])->toBeTrue()
        ->and($data['segments'])->toHaveCount(4)
        ->and($data['segments'][0]['words'])->toBe([['s' => 0, 'e' => 400, 't' => 'Hello'], ['s' => 450, 'e' => 900, 't' => 'there,']])
        ->and($data['segments'][1]['words'])->toBe([])
        ->and($data['speaker_names'])->toBe(['Client' => 'Jane'])
        ->and($data['duration_ms'])->toBe(3_730_000);
});

it('prefers the final transcript, but falls back to live captions while the final one has no text', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [], transcript: ['status' => TranscriptStatus::Processing]);
    Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => 'live', 'provider' => 'device', 'status' => TranscriptStatus::Completed,
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'live words']]]);

    $data = TranscriptPlayerEntry::payload($session->fresh());

    expect($data['kind'])->toBe('live')
        ->and($data['status'])->toBe('processing')
        ->and($data['editable'])->toBeFalse()
        ->and($data['segments'][0]['text'])->toBe('live words');
});

it('plays the decrypted recording from the admin media route, or explains why there is no audio', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    expect(adminPlayerPage($this, $session))->toContain('Audio is not available');
    $recording = $this->readyRecording($session);
    $audio = adminPlayerNode(adminPlayerPage($this, $session))->getElementsByTagName('audio')->item(0);

    expect($audio)->not->toBeNull()
        ->and($audio->getAttribute('src'))->toBe(route('admin.recordings.stream', ['recording' => $recording->id]))
        ->and($audio->getAttribute('x-ref'))->toBe('audio');
});

it('offers speed, skip silence, follow, editing and copying in the toolbar', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $this->readyRecording($session);

    $html = adminPlayerPage($this, $session);

    expect($html)->toContain('aria-label="Playback speed"')
        ->toContain('Skip silence')->toContain('Follow')
        ->toContain("editing ? 'Done editing' : 'Edit text'")
        ->toContain('Copy with timestamps');
});

// --- Behaviour (Node) ------------------------------------------------------------------------------------------

it('groups consecutive lines of a speaker and uses renamed speakers', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'One.'],
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => 'Two.'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 2000, 'end_ms' => 3000, 'text' => 'Three.'],
    ]);
    $session->update(['speaker_names' => ['Client' => 'Jane']]);

    $result = adminRunPlayerScript(adminPlayerPage($this, $session));

    expect($result['blocks'])->toBe([['speaker' => 'Me', 'items' => [0, 1]], ['speaker' => 'Client', 'items' => [2]]])
        ->and($result['text'])->toBe("Me: One.\nMe: Two.\nJane: Three.");
});

it('copies "[m:ss] Speaker: text" lines like the desktop app and reports Copied or Copy failed', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $html = adminPlayerPage($this, $session);

    $ok = adminRunPlayerScript($html, ['copy' => 'ok']);
    expect($ok['times'])->toBe("[0:00] Me: Hello there, this is Farah.\n[0:05] Client: Hi, how can I help?\n[1:05] Me: We spoke about the Q3 roadmap.\n[1:02:05] Client: One hour later.")
        ->and($ok['copy']['writes'])->toBe([$ok['text'], $ok['times']])
        ->and($ok['copy']['states'])->toBe(['text', 'times']);
    expect(adminRunPlayerScript($html, ['copy' => 'reject'])['copy']['states'])->toBe(['failed', 'failed']);

    $module = realpath(base_path('../desktop/src/lib/format.ts'));
    if ($module !== false) {
        $desktop = adminRunPlayerScript($html, ['desktopModule' => $module]);
        expect($desktop['text'])->toBe($desktop['desktop']['text'])->and($desktop['times'])->toBe($desktop['desktop']['times']);
    }
});

it('leaves the speaker out when only one person speaks', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [
        ['channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'A short voice note.'],
        ['channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 1_000, 'end_ms' => 2_000, 'text' => 'Second sentence.'],
    ]);

    expect(adminRunPlayerScript(adminPlayerPage($this, $session))['times'])->toBe("[0:00] A short voice note.\n[0:01] Second sentence.");
});

it('follows the line and the word being played and seeks when asked', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    $result = adminRunPlayerScript(adminPlayerPage($this, $session), ['sync' => [0, 0.5, 4.99, 5, 65, 4000], 'seekTo' => 65_000]);

    expect(array_column($result['sync'], 'active'))->toBe([0, 0, 0, 1, 2, 3])
        ->and(array_column($result['sync'], 'word'))->toBe([0, 1, 1, -1, -1, -1])
        ->and($result['seek'])->toBe(['currentTime' => 65, 'playing' => true]);
});

it('highlights nothing before the first line starts', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 2_000, 'end_ms' => 3_000, 'text' => 'late start']]);

    expect(array_column(adminRunPlayerScript(adminPlayerPage($this, $session), ['sync' => [0, 1.9, 2]])['sync'], 'active'))->toBe([-1, -1, 0]);
});

it('answers Space, K, J, L and the arrow keys, and leaves controls and text fields alone', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $html = adminPlayerPage($this, $session);
    $player = adminPlayerNode($html);
    expect($player->getAttribute('tabindex'))->toBe('0')
        ->and($player->getAttribute('x-on:keydown'))->toBe('onKey($event)')
        ->and($player->getAttribute('aria-label'))->toContain('Space');

    $result = adminRunPlayerScript($html, ['keys' => [
        ['key' => ' ', 'tag' => 'DIV'], ['key' => 'k', 'tag' => 'DIV'], ['key' => 'j', 'tag' => 'DIV'], ['key' => 'L', 'tag' => 'DIV'],
        ['key' => 'ArrowLeft', 'tag' => 'DIV'], ['key' => 'ArrowRight', 'tag' => 'DIV'], ['key' => ' ', 'tag' => 'BUTTON'],
        ['key' => 'k', 'tag' => 'TEXTAREA'], ['key' => 'Enter', 'tag' => 'DIV'],
    ]]);

    expect($result['keys'])->toBe([
        ['prevented' => true, 'paused' => false, 'time' => 30],   // Space plays
        ['prevented' => true, 'paused' => true, 'time' => 30],    // K pauses
        ['prevented' => true, 'paused' => true, 'time' => 20],    // J: back 10 s
        ['prevented' => true, 'paused' => true, 'time' => 30],    // L: forward 10 s
        ['prevented' => true, 'paused' => true, 'time' => 25],    // ←: back 5 s
        ['prevented' => true, 'paused' => true, 'time' => 30],    // →: forward 5 s
        ['prevented' => false, 'paused' => true, 'time' => 30],   // Space on a button keeps its native action
        ['prevented' => false, 'paused' => true, 'time' => 30],   // typing in a line being edited is left alone
        ['prevented' => false, 'paused' => true, 'time' => 30],   // other keys are left alone
    ]);
});

it('skips silences longer than 1.5 seconds and changes the speed', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    $result = adminRunPlayerScript(adminPlayerPage($this, $session), ['skip' => [2, 4.5, 10], 'rate' => 1.5]);

    // In speech nothing moves; 0.5 s before the next line is too short to skip; in a long gap it jumps near the next line.
    expect($result['skip'])->toBe([2, 4.5, 64.8])->and($result['rate'])->toBe(1.5);
});

it('places lines, bookmarks and the playhead on the timeline by time', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    $result = adminRunPlayerScript(adminPlayerPage($this, $session));

    expect($result['pct'])->toBe(['0%', '50%', '100%'])->and($result['clock'])->toBe(['0:00', '1:05', '1:02:05']);
});

it('saves an edited line through Livewire and shows the new state, or the reason it was refused', function () {
    adminNeedsNode($this);
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());
    $html = adminPlayerPage($this, $session);
    $saved = TranscriptPlayerEntry::payload($session->fresh());
    $saved['segments'][0]['text'] = 'Hello there, this is Farah Khan.';

    $ok = adminRunPlayerScript($html, ['save' => 'ok', 'text' => 'Hello there, this is Farah Khan.', 'saved' => $saved]);
    expect($ok['save']['error'])->toBeNull()
        ->and($ok['save']['d']['segments'][0]['text'])->toBe('Hello there, this is Farah Khan.')
        ->and($ok['save']['d']['saved'])->toBe([0, 'Hello there, this is Farah Khan.'])
        ->and($ok['save']['saving'])->toBeNull();

    $refused = adminRunPlayerScript($html, ['save' => 'refuse']);
    expect($refused['save']['error'])->toBe('Only a finished transcript can be edited.');
});

// --- Safety and accessibility ---------------------------------------------------------------------------------

it('keeps hostile text inside the data, never in the markup', function () {
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
    foreach ((new DOMXPath($player->ownerDocument))->query('.//*[@*[starts-with(name(), "on")]]', $player) as $element) {
        $this->fail('Inline event handler found on <'.$element->nodeName.'>');
    }
    if (adminNodeAvailable()) {
        $result = adminRunPlayerScript($html);
        expect(array_column($result['d']['segments'], 'text'))->toBe($payloads)
            ->and($result['d']['segments'][0]['speaker'])->toBe('<i>Mallory</i>');
    }
    // Lines are rendered with x-text, which never parses HTML.
    expect($html)->toContain('x-text="d.segments[i].text"')->not->toContain('x-html');
});

it('styles the player for light and dark themes with a visible focus ring', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    expect(adminPlayerPage($this, $session))->toContain('.dark .el-player')
        ->toContain(':focus-visible')
        ->toContain('outline: 2px solid var(--el-focus)')
        ->toContain('@media (max-width: 640px)')
        ->toContain('prefers-reduced-motion');
});

it('gives the light-theme text colours at least 4.5:1 contrast on the player background', function () {
    $luminance = function (string $hex): float {
        $rgb = array_map(fn (string $c): float => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
        $lin = array_map(fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    };
    $ratio = fn (string $a, string $b): float => (max($luminance($a), $luminance($b)) + 0.05) / (min($luminance($a), $luminance($b)) + 0.05);
    $view = (string) file_get_contents(resource_path('views/filament/infolists/transcript-player.blade.php'));
    preg_match('/\.el-player \{ (--el-border.*?)color: var\(--el-text\)/s', $view, $light);
    preg_match_all('/--el-(text|muted|me|client): (#[0-9A-F]{6})/', $light[1] ?? '', $colours, PREG_SET_ORDER);

    expect($colours)->toHaveCount(4);
    // Worst case background: the active-line tint (violet at 14 %) over white, about #EEE8FD.
    foreach ($colours as [, $name, $hex]) {
        expect($ratio($hex, '#FFFFFF'))->toBeGreaterThanOrEqual(4.5, "{$name} on white")
            ->and($ratio($hex, '#EEE8FD'))->toBeGreaterThanOrEqual(4.5, "{$name} on the active line");
    }
});

it('announces copy results and saving to assistive technology, and errors as alerts', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, adminCallSegments());

    $player = adminPlayerNode(adminPlayerPage($this, $session));
    $xpath = new DOMXPath($player->ownerDocument);
    $live = $xpath->query('.//*[@aria-live="polite"]', $player)->item(0);
    $alert = $xpath->query('.//*[@role="alert"]', $player)->item(0);

    expect($live)->not->toBeNull()
        ->and($live->getAttribute('x-text'))->toContain('Copied')->and($live->getAttribute('x-text'))->toContain('Saving')
        ->and($alert)->not->toBeNull();
});
