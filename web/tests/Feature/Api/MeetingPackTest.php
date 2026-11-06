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
            ->and($html)->toContain('[Screen 2 at 14:32]</span> <span dir="auto">The client&#039;s invoice page with the Pay button</span>')
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
            ->and($at('I see the invoice now'))->toBeLessThan($at('[Screen 2 at 14:32]'))
            ->and($at('[Screen 2 at 14:32]'))->toBeLessThan($at('Click here to pay'));
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
    it('holds transcript.md with each screen as a Markdown image and its description, and the images folder', function () {
        $response = meetingPack($this, 'format=zip')->assertOk();
        $entries = packEntries($response);

        expect($response->headers->get('Content-Type'))->toBe('application/zip')
            ->and($response->headers->get('Content-Disposition'))->toContain('Acme kickoff '.$this->session->started_at->format('Y-m-d').'.zip')
            ->and(array_keys($entries))->toEqualCanonicalizing(['transcript.md', 'images/screen-01.png', 'images/screen-02.webp'])
            ->and($entries['images/screen-01.png']['body'])->toBe($this->png)
            ->and($entries['images/screen-02.webp']['body'])->toBe($this->webp)
            ->and($entries['images/screen-02.webp']['comp_method'])->toBe(ZipArchive::CM_STORE);
        $md = (string) $entries['transcript.md']['body'];
        expect($md)->toStartWith("# Acme kickoff\n")
            ->and($md)->toContain('- **People:** Me, Client, Omar Khan')
            ->and($md)->toContain("![Screen 2 at 14:32](images/screen-02.webp \"Screen 2 at 14:32\")\n*The client's invoice page with the Pay button*")
            ->and($md)->toContain('![Screen 1 at 0:30](images/screen-01.png "Screen 1 at 0:30")')
            ->and(strpos($md, 'Screen 2 at 14:32'))->toBeLessThan(strpos($md, 'Click here to pay'))
            ->and(strpos($md, 'I see the invoice now'))->toBeLessThan(strpos($md, 'Screen 2 at 14:32'));
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
