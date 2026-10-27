<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\ShareLink;
use App\Models\ShareLinkView;
use App\Models\Transcript;
use App\Services\ShareLinks;
use App\Services\TranscriptExporter;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "GET /api/transcripts/{id}/export" and "Share links" (since v1.1).
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->session = CaptureSession::factory()->create([
        'user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'title' => 'Acme: kickoff/plan',
        'status' => SessionStatus::Completed, 'started_at' => Carbon::parse('2026-10-24 09:00:00'), 'duration_ms' => 3_725_000,
        'speaker_names' => ['Client' => 'Jane'],
    ]);
    $this->transcript = $this->session->transcripts()->create([
        'kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x',
        'segments' => [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1200, 'text' => 'Hello <team> & co.'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 3_661_500, 'end_ms' => 3_664_000, 'text' => 'قیمت ٹھیک ہے'],
        ],
    ]);
    $this->session->markers()->create(['at_ms' => 65_000, 'tag' => 'Budget', 'note' => 'max 5k', 'source' => 'device']);
});

function exportOf(object $test, string $format, string $extra = ''): TestResponse
{
    return $test->get("/api/transcripts/{$test->transcript->id}/export?format={$format}{$extra}", ['Authorization' => 'Bearer '.$test->token]);
}

describe('exports', function () {
    it('writes plain text with times and renamed speakers, or without either', function () {
        $response = exportOf($this, 'txt')->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('text/plain')
            ->and($response->getContent())->toBe("[0:00] Me: Hello <team> & co.\n[1:01:01] Jane: قیمت ٹھیک ہے\n");

        expect(exportOf($this, 'txt', '&timestamps=0&speakers=0')->getContent())->toBe("Hello <team> & co.\nقیمت ٹھیک ہے\n");
    });

    it('names the file after the title and date, safe for every file system', function () {
        $disposition = exportOf($this, 'md')->assertOk()->headers->get('Content-Disposition');

        expect($disposition)->toContain('attachment')
            ->and($disposition)->toContain('filename="Acme kickoff plan 2026-10-24.md"')
            ->and(TranscriptExporter::filename($this->session, 'srt'))->toBe('Acme kickoff plan 2026-10-24.srt');
        $this->session->update(['title' => 'قیمت پر بات']);
        $urdu = exportOf($this, 'txt')->headers->get('Content-Disposition');
        expect($urdu)->toContain("filename*=utf-8''")->and($urdu)->toContain('2026-10-24.txt');
    });

    it('writes Markdown with escaped text and the bookmarks', function () {
        $md = exportOf($this, 'md')->assertOk()->getContent();

        expect($md)->toStartWith('# Acme: kickoff/plan')
            ->and($md)->toContain('**Me** `0:00`: Hello \<team\> & co.')
            ->and($md)->toContain('## Bookmarks')
            ->and($md)->toContain('- `1:05` Budget — max 5k');
    });

    it('writes SRT and VTT cues with speaker names', function () {
        $srt = exportOf($this, 'srt')->assertOk()->getContent();
        expect($srt)->toContain("1\n00:00:00,000 --> 00:00:01,200\nMe: Hello <team> & co.")
            ->and($srt)->toContain("2\n01:01:01,500 --> 01:01:04,000\nJane: قیمت ٹھیک ہے");

        $vtt = exportOf($this, 'vtt')->assertOk()->getContent();
        expect($vtt)->toStartWith("WEBVTT\n")
            ->and($vtt)->toContain("00:00:00.000 --> 00:00:01.200\n<v Me>Hello &lt;team&gt; &amp; co.")
            ->and($vtt)->toContain('<v Jane>قیمت ٹھیک ہے');
    });

    it('writes JSON with the session, the named segments and the bookmarks', function () {
        $json = json_decode(exportOf($this, 'json')->assertOk()->getContent(), true);

        expect($json['session']['title'])->toBe('Acme: kickoff/plan')
            ->and($json['transcript']['segments'][1]['speaker'])->toBe('Jane')
            ->and($json['transcript']['segments'][1]['label'])->toBe('Client')
            ->and($json['speaker_names'])->toBe(['Client' => 'Jane'])
            ->and($json['markers'][0])->toBe(['at_ms' => 65_000, 'tag' => 'Budget', 'note' => 'max 5k']);
    });

    it('writes a Word document that opens, with right-to-left Urdu paragraphs', function () {
        $bytes = exportOf($this, 'docx')->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'docx-test');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $document = (string) $zip->getFromName('word/document.xml');
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $zip->close();
        @unlink($path);

        expect($types)->toContain('wordprocessingml.document.main+xml')
            ->and($document)->toContain('Hello &lt;team&gt; &amp; co.')
            ->and($document)->toContain('<w:bidi/>')
            ->and($document)->toContain('قیمت ٹھیک ہے')
            ->and(simplexml_load_string($document))->not->toBeFalse();
    });

    it('writes a print-ready page for PDF with escaped text and per-line direction', function () {
        $html = exportOf($this, 'html')->assertOk()->getContent();

        expect($html)->toContain('<p dir="auto">')
            ->and($html)->toContain('Hello &lt;team&gt; &amp; co.')
            ->and($html)->toContain('@media print')
            ->and($html)->not->toContain('<team>');
    });

    it('refuses unfinished, foreign and unknown-format exports', function () {
        $pending = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id]);
        $unfinished = $pending->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]);
        $this->getJson("/api/transcripts/{$unfinished->id}/export?format=txt")->assertStatus(409)->assertJsonPath('code', 'transcript_not_ready');
        $this->getJson("/api/transcripts/{$this->transcript->id}/export?format=pdf")->assertUnprocessable();
        [$other] = $this->enroll();
        $foreign = CaptureSession::factory()->create(['user_id' => $other->user_id, 'device_id' => $other->id]);
        $theirs = $foreign->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'segments' => []]);
        $this->getJson("/api/transcripts/{$theirs->id}/export?format=txt")->assertNotFound();
    });

    it('decides right-to-left by the script', function () {
        expect(TranscriptExporter::isRtl('قیمت ٹھیک ہے'))->toBeTrue()
            ->and(TranscriptExporter::isRtl('Hello'))->toBeFalse()
            ->and(TranscriptExporter::isRtl('कीमत ठीक है'))->toBeFalse()
            ->and(TranscriptExporter::clock(3_725_000))->toBe('1:02:05')
            ->and(TranscriptExporter::cueTime(3_661_500, ','))->toBe('01:01:01,500');
    });
});

describe('share links', function () {
    function makeShare(object $test, array $body = []): array
    {
        return $test->postJson("/api/sessions/{$test->session->id}/shares", array_merge(['expires_in' => 86_400], $body))->assertCreated()->json();
    }

    it('makes a link that shows the transcript once opened, and logs the view', function () {
        $share = makeShare($this);
        expect($share['url'])->toStartWith(url('/s/'))
            ->and($share['has_password'])->toBeFalse()
            ->and($share['active'])->toBeTrue()
            ->and(ShareLink::query()->sole()->token_hash)->not->toContain(basename($share['url']));

        $this->app['auth']->forgetGuards();
        $page = $this->withHeaders(['Authorization' => ''])->get(parse_url($share['url'], PHP_URL_PATH))->assertOk();
        $page->assertSee('Acme: kickoff/plan')->assertSee('Jane')->assertSee('قیمت ٹھیک ہے')->assertSee('Hello &lt;team&gt; &amp; co.', false);
        expect($page->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
            ->and($page->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($page->getContent())->not->toContain('<audio');
        expect(ShareLink::query()->sole()->view_count)->toBe(1)
            ->and(ShareLinkView::query()->sole()->outcome)->toBe('viewed')
            ->and(ShareLinkView::query()->sole()->ip_hash)->toHaveLength(64);
    });

    it('asks for the password, refuses a wrong one and opens with the right one', function () {
        $share = makeShare($this, ['password' => 'correct horse']);
        $path = parse_url($share['url'], PHP_URL_PATH);

        $this->get($path)->assertOk()->assertSee('This transcript is protected')->assertDontSee('قیمت');
        $this->post($path, ['password' => 'wrong one'])->assertStatus(422)->assertSee('That password is not right.');
        $this->post($path, ['password' => 'correct horse'])->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('قیمت ٹھیک ہے');

        expect(ShareLinkView::query()->pluck('outcome')->all())->toBe(['wrong_password', 'viewed']);
    });

    it('uses a 256-bit random token and never repeats one', function () {
        $tokens = collect(range(1, 5))->map(fn () => basename(makeShare($this)['url']));

        expect($tokens->unique())->toHaveCount(5);
        foreach ($tokens as $token) {
            expect(strlen((string) base64_decode(strtr($token, '-_', '+/'), true)))->toBeGreaterThanOrEqual(32);
        }
    });

    it('slows down password guessing and keeps unlocking one link from opening another', function () {
        $first = makeShare($this, ['password' => 'correct horse']);
        $second = makeShare($this, ['password' => 'battery staple']);
        $path = parse_url($first['url'], PHP_URL_PATH);
        $other = parse_url($second['url'], PHP_URL_PATH);

        $this->post($path, ['password' => 'correct horse'])->assertRedirect($path);
        $this->get($other)->assertOk()->assertSee('This transcript is protected')->assertDontSee('قیمت');

        foreach (range(1, 10) as $attempt) {
            $this->post($other, ['password' => "guess {$attempt}"])->assertStatus(422);
        }
        $this->post($other, ['password' => 'battery staple'])->assertStatus(429);
        expect(ShareLinkView::query()->where('outcome', 'wrong_password')->count())->toBe(10);
    });

    it('stops working when it expires, is revoked, or the recording is deleted', function () {
        $expiring = makeShare($this, ['expires_in' => 3_600]);
        $revoked = makeShare($this);
        $this->deleteJson('/api/shares/'.$revoked['id'])->assertNoContent();

        $this->get(parse_url($revoked['url'], PHP_URL_PATH))->assertNotFound()->assertSee('This link is no longer available');
        $this->travel(61)->minutes();
        $this->get(parse_url($expiring['url'], PHP_URL_PATH))->assertNotFound();

        $live = makeShare($this);
        $this->session->delete();
        $this->get(parse_url($live['url'], PHP_URL_PATH))->assertNotFound();
    });

    it('answers the same for an unknown or malformed token', function () {
        $this->get('/s/'.str_repeat('A', 43))->assertNotFound()->assertSee('no longer available');
        $this->get('/s/short')->assertNotFound();
    });

    it('streams the audio only for a link that includes it', function () {
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000));
        $without = $this->postJson("/api/sessions/{$session->id}/shares", ['expires_in' => 3_600])->assertCreated()->json();
        $with = $this->postJson("/api/sessions/{$session->id}/shares", ['expires_in' => 3_600, 'include_audio' => true])->assertCreated()->json();

        $this->get(parse_url($without['url'], PHP_URL_PATH).'/audio')->assertNotFound();
        $path = parse_url($with['url'], PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('<audio', false);
        $audio = $this->get($path.'/audio')->assertOk();
        expect($audio->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
        expect(ShareLinkView::query()->where('outcome', 'audio')->count())->toBe(1);
    });

    it('keeps the audio of a password link behind the password', function () {
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000));
        $share = $this->postJson("/api/sessions/{$session->id}/shares", ['expires_in' => 3_600, 'include_audio' => true, 'password' => 'secret12'])->assertCreated()->json();

        $this->get(parse_url($share['url'], PHP_URL_PATH).'/audio')->assertNotFound();
    });

    it('lists the links without their secret and revokes only the owner\'s', function () {
        makeShare($this, ['password' => 'abcdef']);
        $list = $this->getJson("/api/sessions/{$this->session->id}/shares")->assertOk()->json('data');

        expect($list)->toHaveCount(1)->and($list[0])->not->toHaveKey('url')->and($list[0])->not->toHaveKey('token_hash')
            ->and($list[0]['has_password'])->toBeTrue();
        [$other, $otherToken] = $this->enroll();
        $this->asToken($otherToken)->deleteJson('/api/shares/'.$list[0]['id'])->assertNotFound()->assertJsonPath('code', 'share_not_found');
    });

    it('validates the link settings and caps active links per recording', function () {
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['expires_in' => 100])->assertUnprocessable();
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['expires_in' => 3_600, 'password' => 'abc'])->assertUnprocessable();
        for ($i = 0; $i < ShareLinks::MAX_ACTIVE_PER_SESSION; $i++) {
            makeShare($this);
        }
        $this->postJson("/api/sessions/{$this->session->id}/shares", ['expires_in' => 3_600])->assertStatus(409)->assertJsonPath('code', 'too_many_shares');
    });

    it('says when the transcript is not ready yet', function () {
        Transcript::query()->delete();
        $share = makeShare($this);

        $this->get(parse_url($share['url'], PHP_URL_PATH))->assertOk()->assertSee('The transcript is not ready yet');
    });
});
