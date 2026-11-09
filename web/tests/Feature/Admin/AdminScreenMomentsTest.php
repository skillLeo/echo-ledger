<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\Screens\ScreenDescriptions;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

require_once __DIR__.'/../Api/Support/screen_helpers.php';

/*
 * Screen moments on the console (v1.5): the screens between the transcript lines and in a Screens tab on the
 * recording, the project's Screens tab, Export → Meeting pack, Settings → Screen moments and the project's switch.
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Http::preventStrayRequests();
    $this->admin = $this->signInAsAdmin();
    $this->isolatedStorage();
    $this->project = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
    $this->session = $this->sessionWithTranscript($this->admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 5000, 'end_ms' => 9000, 'text' => 'Let me share my screen'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 60_000, 'end_ms' => 64_000, 'text' => 'I see the invoice now'],
    ], ['title' => 'Acme kickoff', 'status' => SessionStatus::Completed, 'duration_ms' => 900_000, 'client_id' => $this->project->id]);
    $this->readyRecording($this->session, 'RIFF-acme-kickoff-audio');
    $this->png = screenPicture('png', 1280, 720, seed: 1);
    $this->first = keepScreen($this->session, $this->png, ['at_ms' => 30_000]);
    $this->second = keepScreen($this->session, screenPicture(seed: 2), ['at_ms' => 872_000]);
    $this->second->forceFill(['description' => "The client's invoice page with the Pay button"])->save();
});

/**
 * Runs the player's own Alpine data in node: Copy for AI, where each screen shows, and the large view's keys.
 *
 * @return array<string, mixed>
 */
function screenPlayerScript(string $html): array
{
    expect(preg_match('/<div class="el-player"[^>]*?\sx-data="([^"]*)"/s', $html, $match))->toBe(1);
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eld-screens-'.bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir.'/input.json', (string) json_encode(['expression' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')]));
    file_put_contents($dir.'/run.mjs', <<<'JS'
        import { readFileSync } from 'node:fs';
        const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));
        const data = new Function('return (' + input.expression + ')')();
        data.$nextTick = (f) => f();
        data.$refs = {};
        const key = (k) => data.onKey({ key: k, target: { tagName: 'BUTTON' }, preventDefault() {} });
        const out = {
            ai: data.transcriptText(false, true),
            plain: data.transcriptText(false),
            before: data.screensAt(-1).map((s) => s.index),
            after: data.d.segments.map((s, i) => data.screensAt(i).map((x) => x.index)),
            label: data.screenLabel(data.d.screens[1]),
        };
        data.openShot(0); key('ArrowLeft'); out.first = data.shot; key('ArrowRight'); out.next = data.shot; key('ArrowRight'); out.last = data.shot;
        key('Escape'); out.closed = data.shot;
        console.log(JSON.stringify(out));
        JS);
    try {
        $output = (string) shell_exec('node '.escapeshellarg($dir.'/run.mjs').' '.escapeshellarg($dir.'/input.json').' 2>&1');
    } finally {
        @unlink($dir.'/input.json');
        @unlink($dir.'/run.mjs');
        @rmdir($dir);
    }
    $result = json_decode(trim($output), true);
    expect($result)->toBeArray($output);

    return $result;
}

describe('the recording page', function () {
    it('puts each screen under the line it falls in, in Copy for AI too, and moves between screens with the arrow keys', function () {
        $probe = @shell_exec('node --version 2>&1');
        if (! is_string($probe) || preg_match('/^v\d+/', trim($probe)) !== 1) {
            $this->markTestSkipped('node is not installed');
        }
        $out = screenPlayerScript($this->get('/app/sessions/'.$this->session->id)->assertOk()->getContent());

        // Screen 1 (0:30) falls in the first line's time (from 0:05), Screen 2 (14:32) in the second's (from 1:00).
        expect($out['before'])->toBe([])->and($out['after'])->toBe([[1], [2]])
            ->and($out['label'])->toBe('Screen 2 · 14:32')
            ->and($out['ai'])->toContain("Me: Let me share my screen\n[Screen 1 at 0:30]\nClient: I see the invoice now\n[Screen 2 at 14:32: The client's invoice page with the Pay button]")
            ->and($out['plain'])->not->toContain('[Screen')
            ->and([$out['first'], $out['next'], $out['last'], $out['closed']])->toBe([0, 1, 1, null]);
    });

    it('hands the player its screens in time order with the console links to their pictures', function () {
        $screens = TranscriptPlayerEntry::payload($this->session->fresh())['screens'];

        expect(array_column($screens, 'index'))->toBe([1, 2])
            ->and(array_column($screens, 'at_ms'))->toBe([30_000, 872_000])
            ->and($screens[0]['thumb'])->toBe(route('admin.screens.media', [$this->first->id, 'thumb']))
            ->and($screens[1]['image'])->toBe(route('admin.screens.media', [$this->second->id, 'image']))
            ->and($screens[1]['description'])->toBe("The client's invoice page with the Pay button");
    });

    it('shows the Transcript and Screens tabs, the screens between the lines and the large view', function () {
        $html = $this->panelComponent(ViewCaptureSession::class, ['record' => $this->session->id])->html();

        expect($html)->toContain('aria-label="Transcript and screens"')
            ->and($html)->toContain('screensAt(i)')->toContain('class="el-gallery"')->toContain('class="el-shot"')
            ->and($html)->toContain($this->second->id)
            // Copy for AI puts "[Screen 7 at 14:32: description]" between the lines.
            ->and($html)->toContain("shotLine(s) { return '[Screen ' + s.index + ' at '");
    });

    it('shows no Screens tab for a recording without screens', function () {
        $plain = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']],
            ['status' => SessionStatus::Completed]);

        expect(TranscriptPlayerEntry::payload($plain)['screens'])->toBe([]);
    });

    it('serves the pictures to the signed-in workspace only, decrypted, and none while the recording is in the undo bin', function () {
        $image = $this->get(route('admin.screens.media', [$this->first->id, 'image']))->assertOk();
        expect($image->getContent())->toBe($this->png)->and($image->headers->get('Content-Type'))->toBe('image/png')
            ->and($image->headers->get('Cache-Control'))->toContain('private');
        $this->get(route('admin.screens.media', [$this->first->id, 'thumb']))->assertOk()->assertHeader('Content-Type', 'image/webp');

        $this->session->delete();
        $this->get(route('admin.screens.media', [$this->first->id, 'image']))->assertNotFound();
        auth('web')->logout();
        $this->get(route('admin.screens.media', [$this->first->id, 'image']))->assertRedirect();
    });

    it('offers Export → Meeting pack: a page to print and a ZIP, with or without the audio, in each view', function () {
        $modal = $this->modalHtml($this->panelComponent(ViewCaptureSession::class, ['record' => $this->session->id])->mountAction('download'));

        expect($modal)->toContain('Meeting pack (print)')->toContain('Meeting pack (.zip)')->toContain('Meeting pack with audio (.zip)')
            ->and($modal)->toContain(e(route('admin.sessions.pack', ['session' => $this->session->id, 'format' => 'html'])))
            ->and($modal)->toContain(e(route('admin.sessions.pack', ['session' => $this->session->id, 'format' => 'zip', 'include_audio' => 1])))
            ->and($modal)->toContain('its 2 screens');
    });
});

describe('the console meeting pack', function () {
    it('opens the page inline with a Print button and the screens at their moments', function () {
        $response = $this->get(route('admin.sessions.pack', ['session' => $this->session->id, 'format' => 'html']))->assertOk();
        $html = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

        expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
            ->and($html)->toContain('Print or save as PDF')->toContain('[Screen 1 at 0:30]')->toContain('[Screen 2 at 14:32]')
            ->and($html)->toContain('data:image/png;base64,'.base64_encode($this->png))
            ->and($html)->not->toContain('window.addEventListener');
    });

    it('downloads the ZIP, and answers 404 without a finished transcript or for an unknown format', function () {
        $zip = $this->get(route('admin.sessions.pack', ['session' => $this->session->id, 'format' => 'zip']))->assertOk();
        expect($zip->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('.zip');

        $this->get('/admin-export/sessions/'.$this->session->id.'/pack/pdf')->assertNotFound();
        $this->session->finalTranscript()->update(['status' => 'processing']);
        $this->get(route('admin.sessions.pack', ['session' => $this->session->id, 'format' => 'html']))->assertNotFound();
    });
});

describe('the project page', function () {
    it('has a Screens tab with the screens of its recordings and subfolders, newest recording first', function () {
        $sub = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Website', 'parent_id' => $this->project->id]);
        $newer = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'client_id' => $sub->id, 'title' => 'Website review', 'started_at' => now()->addMinute()]);
        keepScreen($newer, screenPicture(seed: 3), ['at_ms' => 1000]);

        $page = $this->panelComponent(ViewClient::class, ['record' => $this->project->id])->call('setTab', 'screens');

        $page->assertSee('Screens')->assertSeeInOrder(['Website review', 'Acme kickoff'])
            ->assertSee('Screen 1 · 0:30')->assertSee('Screen 2 · 14:32')->assertSee("The client's invoice page with the Pay button")
            ->assertSee(route('admin.screens.media', [$this->first->id, 'thumb']), false);
    });

    it('explains an empty Screens tab', function () {
        $empty = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Empty']);

        $this->panelComponent(ViewClient::class, ['record' => $empty->id])->call('setTab', 'screens')->assertSee('No screens yet');
    });

    it('turns screen moments off for a project on its Settings', function () {
        $this->panelComponent(EditClient::class, ['record' => $this->project->id])
            ->assertFormSet(['screens' => true])
            ->fillForm(['screens' => false])->call('save')->assertHasNoFormErrors();

        expect($this->project->fresh()->screens)->toBeFalse();
    });
});

describe('settings', function () {
    it('switches Describe screens off and on under Screen moments', function () {
        $page = $this->panelComponent(Settings::class)->set('activeTab', 'recording')->assertSee('Screen moments')->assertSee('Describe screens')
            ->assertSet('data.screens_describe', true);

        $page->set('data.screens_describe', false)->call('save')->assertHasNoFormErrors();
        expect(app(SettingsRepository::class)->get(ScreenDescriptions::SETTING))->toBeFalse();
        $this->panelComponent(Settings::class)->set('data.settings_search', 'screenshots')->assertSet('activeTab', 'recording');
    });
});
