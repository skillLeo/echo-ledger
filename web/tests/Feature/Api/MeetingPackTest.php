<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Screens\MeetingPack;
use App\Services\Views\TranscriptViews;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/screen_helpers.php';

/*
 * docs/API.md "Meeting pack (since v1.5)": GET /api/sessions/{id}/pack?format=html|zip&view=&include_audio=. The
 * transcript in the view with each screen at its moment, "[Screen 7 at 14:32]" and its description.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->audio = Fixtures::audio(4000, 'PACK-AUDIO');
    $this->project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
    $this->session = $this->makeReadySession($this->device, $this->audio, ['status' => SessionStatus::Completed, 'title' => 'Acme kickoff',
        'duration_ms' => 900_000, 'client_id' => $this->project->id]);
    $this->session->forceFill(['caller_name' => 'Omar Khan'])->save();
    $segments = [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 5000, 'end_ms' => 9000, 'text' => 'Let me share my screen'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 60_000, 'end_ms' => 64_000, 'text' => 'I see the invoice now'],
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 900_000, 'end_ms' => 902_000, 'text' => 'Click here to pay'],
    ];
    $this->transcript = $this->session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed,
        'language' => 'en', 'text' => 'x', 'segments' => $segments]);
    $this->png = screenPicture('png', 1280, 720, seed: 1);
    $this->webp = screenPicture('webp', 1280, 720, seed: 2);
    $this->first = keepScreen($this->session, $this->png, ['at_ms' => 30_000]);
    $this->second = keepScreen($this->session, $this->webp, ['at_ms' => 872_000]);
    $this->second->forceFill(['description' => "The client's invoice page with the Pay button", 'ocr_text' => 'Invoice #2041'])->save();
});

function meetingPack(object $test, string $query): TestResponse
{
    return $test->get("/api/sessions/{$test->session->id}/pack?{$query}", ['Accept' => 'application/json']);
}

function packFile(TestResponse $response): string
{
    return (string) file_get_contents($response->baseResponse->getFile()->getPathname());
}

/** @return array<string, array<string, mixed>> entry name => stat */
function packEntries(TestResponse $response): array
{
    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = (array) $zip->statIndex($i);
        $entries[(string) $stat['name']] = $stat + ['body' => $zip->getFromIndex($i)];
    }
    $zip->close();

    return $entries;
}

describe('html', function () {
    it('is one page: a title page, then the lines with each screen at its moment, its label, description and embedded picture', function () {
        $response = meetingPack($this, 'format=html')->assertOk();
        $html = packFile($response);

        expect($response->headers->get('Content-Type'))->toBe('text/html; charset=utf-8')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('Acme kickoff '.$this->session->started_at->format('Y-m-d').'.html')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
        expect($html)->toContain('<h1 dir="auto">Acme kickoff</h1>')
            ->and($html)->toContain('<dt>Length</dt><dd dir="auto">15:00</dd>')
            ->and($html)->toContain('<dt>People</dt><dd dir="auto">Me, Client, Omar Khan</dd>')
            ->and($html)->toContain('<dt>Project</dt><dd dir="auto">Acme</dd>')
            ->and($html)->toContain('<dt>Screens</dt><dd dir="auto">2</dd>')
            ->and($html)->toContain('[Screen 1 at 0:30]')
            ->and($html)->toContain('<span class="label" dir="auto">[Screen 2 at 14:32 — The client&#039;s invoice page with the Pay button]</span>')
            ->and($html)->toContain('src="data:image/png;base64,'.base64_encode($this->png).'"')
            ->and($html)->toContain('src="data:image/webp;base64,'.base64_encode($this->webp).'"')
            ->and($html)->toContain('@media print')->toContain('page-break-after:always')->toContain('break-inside:avoid')
            // Nothing outside the page: no links to fetch, no script.
            ->and($html)->not->toContain('<script')->not->toContain('http://')->not->toContain('https://')
            // English only: no Urdu font is carried.
            ->and($html)->not->toContain('@font-face');
        // In time order: line 1 (0:05), screen 1 (0:30), line 2 (1:00), screen 2 (14:32), line 3 (15:00).
        $at = fn (string $needle): int => (int) strpos($html, $needle);
        expect($at('Let me share my screen'))->toBeLessThan($at('[Screen 1 at 0:30]'))
            ->and($at('[Screen 1 at 0:30]'))->toBeLessThan($at('I see the invoice now'))
            ->and($at('I see the invoice now'))->toBeLessThan($at('[Screen 2 at 14:32 —'))
            ->and($at('[Screen 2 at 14:32 —'))->toBeLessThan($at('Click here to pay'));
        // Only the pack itself, which is deleted once it is sent.
        expect($this->workFiles())->toBe([$response->baseResponse->getFile()->getPathname()]);
    });

    it('sets Urdu lines right to left in Noto Nastaliq, carried inside the page', function () {
        $segments = $this->transcript->segments;
        $segments[1]['text'] = 'یہ انوائس کا صفحہ ہے';
        $this->transcript->update(['language' => 'ur', 'segments' => $segments]);

        $html = packFile(meetingPack($this, 'format=html')->assertOk());

        expect($html)->toContain('@font-face{font-family:"Noto Nastaliq Urdu";src:url(data:font/woff2;base64,')
            ->and($html)->toContain('<p class="line" dir="rtl">')
            ->and($html)->toContain('یہ انوائس کا صفحہ ہے');
    });

    it('sets an Arabic call in Noto Naskh, and its Urdu view in Noto Nastaliq', function () {
        $segments = $this->transcript->segments;
        $segments[1]['text'] = 'هذه صفحة الفاتورة';
        $this->transcript->update(['language' => 'ar', 'segments' => $segments]);
        $naskh = '@font-face{font-family:"Noto Naskh Arabic";src:url(data:font/woff2;base64,';
        $nastaliq = '@font-face{font-family:"Noto Nastaliq Urdu";src:url(data:font/woff2;base64,';

        // The Urdu view is not made yet: the Arabic words are shown, in Naskh.
        $unmade = packFile(meetingPack($this, 'format=html&view=urdu')->assertOk());
        expect($unmade)->toContain($naskh)->not->toContain($nastaliq)->toContain('هذه صفحة الفاتورة');

        $meta = ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint(new Transcript(['segments' => $segments])),
            'english' => ['Let me share my screen', 'This is the invoice page', 'Click here to pay'],
            'urdu' => ['میں اپنی اسکرین دکھاتا ہوں', 'یہ انوائس کا صفحہ ہے', 'ادائیگی کے لیے یہاں کلک کریں']]];
        $this->transcript->update(['meta' => $meta]);

        $urdu = packFile(meetingPack($this, 'format=html&view=urdu')->assertOk());
        $original = packFile(meetingPack($this, 'format=html&view=original')->assertOk());

        expect($urdu)->toContain($nastaliq)->not->toContain($naskh)->toContain('یہ انوائس کا صفحہ ہے')
            ->and($urdu)->toContain('p.line[dir=rtl] .text{font-family:"Noto Nastaliq Urdu"')
            ->and($original)->toContain($naskh)->not->toContain($nastaliq)->toContain('هذه صفحة الفاتورة');
    });

    it('writes the transcript in the chosen Show as view, the original words when it is not made', function () {
        $segments = $this->transcript->segments;
        $meta = ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint(new Transcript(['segments' => $segments])),
            'roman' => ['Main screen share karta hoon', 'Mujhe invoice nazar aa rahi hai', 'Pay ke liye yahan click karein']]];
        $this->transcript->update(['language' => 'ur', 'meta' => $meta]);

        expect(packFile(meetingPack($this, 'format=html&view=roman')->assertOk()))->toContain('Mujhe invoice nazar aa rahi hai')->not->toContain('I see the invoice now')
            ->and(packFile(meetingPack($this, 'format=html&view=english')->assertOk()))->toContain('I see the invoice now')
            ->and(packFile(meetingPack($this, 'format=html&view=original')->assertOk()))->toContain('I see the invoice now');
    });

    it('embeds the thumbnails instead when the pictures together are too large for one page', function () {
        unscoped(fn () => Screen::query()->whereKey($this->first->id)->update(['bytes' => MeetingPack::FULL_PICTURES_BYTES]));

        $html = packFile(meetingPack($this, 'format=html')->assertOk());

        expect($html)->not->toContain(base64_encode($this->png))->and(substr_count($html, 'src="data:image/webp;base64,'))->toBe(2);
    });
});

describe('zip', function () {
    it('holds transcript.md with each screen\'s marker and picture, the screens folder by name, and screens.md', function () {
        $response = meetingPack($this, 'format=zip')->assertOk();
        $entries = packEntries($response);
        $first = 'screens/S01_0-30_screen.png';
        $second = 'screens/S02_14-32_the-clients-invoice-page-with-the-pay.webp';

        expect($response->headers->get('Content-Type'))->toBe('application/zip')
            ->and($response->headers->get('Content-Disposition'))->toContain('Acme kickoff '.$this->session->started_at->format('Y-m-d').'.zip')
            ->and(array_keys($entries))->toEqualCanonicalizing(['transcript.md', 'screens.md', $first, $second])
            ->and($entries[$first]['body'])->toBe($this->png)
            ->and($entries[$second]['body'])->toBe($this->webp)
            ->and($entries[$second]['comp_method'])->toBe(ZipArchive::CM_STORE);
        $md = (string) $entries['transcript.md']['body'];
        expect($md)->toStartWith("# Acme kickoff\n")
            ->and($md)->toContain('- **People:** Me, Client, Omar Khan')
            ->and($md)->toContain("**\\[Screen 2 at 14:32 — The client's invoice page with the Pay button\\]**\n![Screen 2 at 14:32](<{$second}> \"Screen 2 at 14:32\")")
            ->and($md)->toContain("**\\[Screen 1 at 0:30\\]**\n![Screen 1 at 0:30](<{$first}> \"Screen 1 at 0:30\")")
            ->and(strpos($md, 'Screen 2 at 14:32'))->toBeLessThan(strpos($md, 'Click here to pay'))
            ->and(strpos($md, 'I see the invoice now'))->toBeLessThan(strpos($md, 'Screen 2 at 14:32'));
        $table = (string) $entries['screens.md']['body'];
        expect($table)->toContain('| # | Time | File | Description | What was said |')
            ->and($table)->toContain("| 2 | 14:32 | [`S02_14-32_the-clients-invoice-page-with-the-pay.webp`](<{$second}>) | The client's invoice page with the Pay button |")
            ->and($table)->toContain('| 1 | 0:30 | [`S01_0-30_screen.png`](<'.$first.'>) | — |');
        // The pictures written for the zip are gone; only the zip is left, deleted once it is sent.
        expect($this->workFiles())->toBe([$response->baseResponse->getFile()->getPathname()]);
    });

    it('adds the decrypted audio, stored as it is, with include_audio=1', function () {
        $response = meetingPack($this, 'format=zip&include_audio=1')->assertOk();
        $entries = packEntries($response);
        $name = 'Acme kickoff '.$this->session->started_at->format('Y-m-d').'.ogg';

        expect($entries)->toHaveKey($name)
            ->and($entries[$name]['body'])->toBe($this->audio)
            ->and($entries[$name]['comp_method'])->toBe(ZipArchive::CM_STORE)
            // The decrypted audio went right after the zip was written.
            ->and($this->workFiles())->toBe([$response->baseResponse->getFile()->getPathname()]);
    });
});

describe('refusals', function () {
    it('answers 409 nothing_to_export without a finished transcript', function () {
        $this->transcript->update(['status' => TranscriptStatus::Processing]);

        meetingPack($this, 'format=html')->assertStatus(409)->assertJsonPath('code', 'nothing_to_export');
        meetingPack($this, 'format=zip')->assertStatus(409)->assertJsonPath('code', 'nothing_to_export');
        expect($this->workFiles())->toBe([]);
    });

    it('validates the format and the view', function () {
        meetingPack($this, 'format=pdf')->assertStatus(422)->assertJsonValidationErrors('format');
        meetingPack($this, 'format=html&view=klingon')->assertStatus(422)->assertJsonValidationErrors('view');
    });

    it('is never made of another workspace\'s recording, one in the undo bin, or one a teammate sent', function () {
        $omar = User::factory()->create(['email' => 'omar@example.com']);
        [, $omarToken] = $this->enroll($omar);
        $this->postJson("/api/sessions/{$this->session->id}/send", ['to' => ['omar@example.com']])->assertCreated();

        $this->asToken($omarToken)->getJson("/api/sessions/{$this->session->id}/pack?format=html")->assertNotFound()->assertJsonPath('code', 'session_not_found');
        $this->asToken($this->token)->deleteJson("/api/sessions/{$this->session->id}")->assertNoContent();
        $this->getJson("/api/sessions/{$this->session->id}/pack?format=html")->assertNotFound()->assertJsonPath('code', 'session_not_found');
        expect(unscoped(fn () => CaptureSession::onlyTrashed()->count()))->toBe(1);
    });
});

describe('text', function () {
    it('gives Copy for AI: what the markers mean, the facts, then the transcript with each screen at its moment', function () {
        $response = meetingPack($this, 'format=text')->assertOk();
        $text = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

        expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8')
            ->and($response->headers->get('Content-Disposition'))->toContain('Acme kickoff '.$this->session->started_at->format('Y-m-d').'.txt')
            ->and($text)->toStartWith(MeetingPack::AI_NOTE."\n\nAcme kickoff\n")
            ->and($text)->toContain("People: Me, Client, Omar Khan\n")
            ->and($text)->toContain("I see the invoice now\n[Screen 2 at 14:32 — The client's invoice page with the Pay button]\n[15:00] ")
            ->and($text)->toContain("[Screen 1 at 0:30]\n");
    });
});

describe('parts', function () {
    it('prints a long meeting in parts of about 100 pages, each with its title page; the folder and Copy for AI stay whole', function () {
        $segments = [];
        foreach (range(0, 3999) as $i) {
            $segments[] = ['channel' => 1, 'speaker' => 'Me', 'start_ms' => $i * 1000, 'end_ms' => $i * 1000 + 900, 'text' => "Line {$i} ".str_repeat('words ', 14)];
        }
        $this->transcript->update(['segments' => $segments]);

        $first = meetingPack($this, 'format=html')->assertOk()->assertHeader('X-Pack-Part', '1');
        $parts = (int) $first->headers->get('X-Pack-Parts');
        expect($parts)->toBeGreaterThan(1)
            ->and($first->headers->get('Content-Disposition'))->toContain('part 1 of '.$parts.'.html');
        $html1 = (string) file_get_contents($first->baseResponse->getFile()->getPathname());
        expect($html1)->toContain('Meeting pack · Part 1 of '.$parts)->toContain('Line 0 ')->not->toContain('Line 3999 ');

        $last = meetingPack($this, 'format=html&part='.$parts)->assertOk()->assertHeader('X-Pack-Part', (string) $parts);
        $htmlLast = (string) file_get_contents($last->baseResponse->getFile()->getPathname());
        expect($htmlLast)->toContain('Part '.$parts.' of '.$parts)->toContain('Line 3999 ')->not->toContain('>Line 0 ');
        meetingPack($this, 'format=html&part='.($parts + 1))->assertNotFound();

        meetingPack($this, 'format=text')->assertOk()->assertHeader('X-Pack-Parts', '1');
        meetingPack($this, 'format=zip')->assertOk()->assertHeader('X-Pack-Parts', '1');
    });

    it('keeps each part under about 100 pages and every item in exactly one part, in order', function () {
        $timeline = [];
        foreach (range(1, 3000) as $i) {
            $timeline[] = ['line', ['speaker' => 'Me', 'text' => str_repeat('x', 170), 'start_ms' => $i]];
        }
        $parts = MeetingPack::parts($timeline);
        expect(count($parts))->toBeGreaterThan(1)->and(array_sum(array_map('count', $parts)))->toBe(3000)
            ->and(array_merge(...$parts))->toBe($timeline);
        // 170 characters: 2 printed lines and spacing, 45 a page, plus the title page.
        foreach ($parts as $part) {
            expect(1 + count($part) * 2.4 / 45)->toBeLessThanOrEqual(MeetingPack::PAGES_PER_PART);
        }
        expect(MeetingPack::parts([]))->toBe([[]]);
    });
});
