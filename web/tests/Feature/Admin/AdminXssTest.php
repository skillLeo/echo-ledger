<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\KeytermsRelationManager;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Transcript;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use PHPUnit\Framework\Assert;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

const ADMIN_XSS_SCRIPT = "<script>alert('xss')</script>";
const ADMIN_XSS_IMG = '"><img src=x onerror=alert(1)>';
/** Fits a 16-character column such as a language code (MySQL refuses longer values; SQLite does not check). */
const ADMIN_XSS_SHORT = '"><i>xss</i>';

/** Raw (unescaped) markers that must never appear in any output. */
function adminRawMarkers(): array
{
    return [ADMIN_XSS_SCRIPT, ADMIN_XSS_IMG, '<img src=x onerror=alert(1)>', "<script>alert('xss')", '<svg onload=alert(1)>'];
}

/** What a browser would hold for the page: text nodes and attribute values with entities decoded, markup removed. */
function adminVisibleText(string $html): string
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();

    $attributes = [];
    foreach ((new DOMXPath($dom))->query('//@*') as $attribute) {
        $attributes[] = $attribute->value;
    }

    return $dom->textContent."\n".implode("\n", $attributes);
}

function adminAssertEscaped(string $output, string $payload, string $where, bool $mustShow = true): void
{
    foreach (adminRawMarkers() as $marker) {
        Assert::assertStringNotContainsString($marker, $output, "raw markup from the payload leaked into {$where}");
    }
    if ($mustShow) {
        Assert::assertStringContainsString($payload, adminVisibleText($output), "the payload should be shown as text in {$where}");
    }
}

it('escapes a session title everywhere it is shown', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => ADMIN_XSS_SCRIPT]);

    adminAssertEscaped($this->panelComponent(ListCaptureSessions::class)->html(), ADMIN_XSS_SCRIPT, 'the sessions list');
    adminAssertEscaped($this->get('/admin/sessions/'.$session->id)->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the session page');
    adminAssertEscaped($this->get('/admin/sessions')->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the sessions page');

    $delete = $this->panelComponent(ListCaptureSessions::class)->mountAction(TestAction::make(DeleteAction::class)->table($session));
    foreach (adminRawMarkers() as $marker) {
        expect($this->modalHtml($delete))->not->toContain($marker);
    }
});

it('escapes a session title that tries to break out of an attribute', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => ADMIN_XSS_IMG]);

    adminAssertEscaped($this->get('/admin/sessions/'.$session->id)->assertOk()->getContent(), ADMIN_XSS_IMG, 'the session page');
    adminAssertEscaped($this->get('/admin/sessions')->assertOk()->getContent(), ADMIN_XSS_IMG, 'the sessions page');
});

it('escapes a client name in the clients list, the edit page and every place a session shows it', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id, 'name' => ADMIN_XSS_SCRIPT, 'notes' => ADMIN_XSS_IMG, 'language' => ADMIN_XSS_SHORT]);
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'client_id' => $client->id, 'title' => 'Plain title']);

    adminAssertEscaped($this->panelComponent(ListClients::class)->html(), ADMIN_XSS_SCRIPT, 'the clients list');
    adminAssertEscaped($this->get('/admin/clients')->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the clients page');
    $edit = $this->get('/admin/clients/'.$client->id.'/edit')->assertOk()->getContent();
    adminAssertEscaped($edit, ADMIN_XSS_SCRIPT, 'the client edit page');
    adminAssertEscaped($edit, ADMIN_XSS_IMG, 'the client edit page notes');
    adminAssertEscaped($this->panelComponent(ListCaptureSessions::class)->html(), ADMIN_XSS_SCRIPT, 'the sessions list client column and filter');
    adminAssertEscaped($this->get('/admin/sessions/'.$session->id)->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the session page client entry');
});

it('escapes a client name offered in the voice-note upload dialog', function () {
    $admin = $this->signInAsAdmin();
    Client::factory()->create(['user_id' => $admin->id, 'name' => ADMIN_XSS_SCRIPT]);

    $list = $this->panelComponent(ListCaptureSessions::class)->mountAction('uploadVoiceNote');

    $modal = $this->modalHtml($list);
    foreach (adminRawMarkers() as $marker) {
        expect($modal)->not->toContain($marker);
    }
});

it('escapes a device name in the devices list, the rename and revoke dialogs and the session views', function () {
    $admin = $this->signInAsAdmin();
    $device = Device::factory()->create(['user_id' => $admin->id, 'name' => ADMIN_XSS_SCRIPT]);
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'device_id' => $device->id]);

    adminAssertEscaped($this->panelComponent(ListDevices::class)->html(), ADMIN_XSS_SCRIPT, 'the devices list');
    adminAssertEscaped($this->get('/admin/devices')->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the devices page');
    // The Device column is optional (hidden by default since C3): show every column first.
    adminAssertEscaped($this->panelComponent(ListCaptureSessions::class)->toggleAllTableColumns()->html(), ADMIN_XSS_SCRIPT, 'the sessions list device column');
    adminAssertEscaped($this->get('/admin/sessions/'.$session->id)->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the session page device entry');

    $rename = $this->panelComponent(ListDevices::class)->mountAction(TestAction::make('rename')->table($device));
    foreach (adminRawMarkers() as $marker) {
        expect($this->modalHtml($rename))->not->toContain($marker);
    }
    $revoke = $this->panelComponent(ListDevices::class)->mountAction(TestAction::make(DeleteAction::class)->table($device));
    foreach (adminRawMarkers() as $marker) {
        expect($this->modalHtml($revoke))->not->toContain($marker);
    }
});

it('escapes keyterms', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $client->keyterms()->create(['term' => ADMIN_XSS_SCRIPT]); // stored raw, as if it bypassed the form

    $html = $this->panelComponent(KeytermsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])->html();

    adminAssertEscaped($html, ADMIN_XSS_SCRIPT, 'the keyterms table');
});

it('escapes the signed-in admin\'s own name in the account menu, profile and dashboard', function () {
    $this->signInAsAdmin(['name' => ADMIN_XSS_SCRIPT]);

    adminAssertEscaped($this->get('/admin')->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the dashboard');
    adminAssertEscaped($this->get('/admin/profile')->assertOk()->getContent(), ADMIN_XSS_SCRIPT, 'the profile page', mustShow: false);
});

it('escapes a provider error message shown on the session page', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id]);
    Transcript::query()->create([
        'capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => ADMIN_XSS_SCRIPT,
        'status' => TranscriptStatus::Failed, 'error' => ADMIN_XSS_IMG, 'language' => ADMIN_XSS_SHORT,
    ]);

    $html = $this->get('/admin/sessions/'.$session->id)->assertOk()->getContent();

    adminAssertEscaped($html, ADMIN_XSS_IMG, 'the error entry');
    adminAssertEscaped($html, ADMIN_XSS_SCRIPT, 'the engine entry');
    adminAssertEscaped($html, ADMIN_XSS_SHORT, 'the language entry');
});

it('escapes free-text settings when the page is shown again', function () {
    $this->signInAsAdmin();
    $settings = app(SettingsRepository::class);
    $settings->set('azure.region', ADMIN_XSS_SCRIPT);
    $settings->set('default_language', ADMIN_XSS_IMG);
    $settings->set('app.pins', [ADMIN_XSS_SCRIPT]);

    $page = $this->get('/admin/settings')->assertOk()->getContent();
    $component = $this->panelComponent(Settings::class)->html();

    foreach ([$page, $component] as $output) {
        foreach (adminRawMarkers() as $marker) {
            expect($output)->not->toContain($marker);
        }
    }
});

it('keeps hostile data that arrives through the device API inert in the console', function () {
    $admin = $this->makeAdmin();
    $code = app(PairingService::class)->createCode($admin);

    // A device with a hostile name registers with a valid one-time code, then uploads a hostile session and transcript.
    $registered = $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => ADMIN_XSS_SCRIPT, 'platform' => 'android', 'app_version' => ADMIN_XSS_IMG])->assertCreated();
    $token = $registered->json('token');
    $this->app['auth']->forgetGuards();

    $created = $this->withToken($token)->postJson('/api/sessions', [
        'client_ref' => 'xss-'.bin2hex(random_bytes(4)), 'source_app' => 'whatsapp', 'title' => ADMIN_XSS_IMG,
        'started_at' => now()->subMinutes(5)->toIso8601String(), 'duration_ms' => 5000, 'language' => 'en',
        'artifacts' => [['role' => 'primary', 'mime' => 'audio/ogg', 'codec' => 'opus', 'channels' => 2, 'sample_rate' => 48000, 'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'duration_ms' => 5000]],
    ])->assertCreated();
    $sessionId = $created->json('session.id');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson("/api/sessions/{$sessionId}/transcripts", [
        'kind' => 'live', 'provider' => 'assemblyai', 'language' => 'en',
        'segments' => [
            ['channel' => 1, 'speaker' => '<b>Me</b>', 'start_ms' => 0, 'end_ms' => 1000, 'text' => ADMIN_XSS_SCRIPT],
            ['channel' => 2, 'speaker' => '<i>Client</i>', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => ADMIN_XSS_IMG],
        ],
    ])->assertCreated();

    // Now the admin looks at all of it.
    $this->app['auth']->forgetGuards();
    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    foreach (['/admin/devices', '/admin/sessions', '/admin/sessions/'.$sessionId] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        foreach (adminRawMarkers() as $marker) {
            Assert::assertStringNotContainsString($marker, $html, "raw markup reached {$url}");
        }
    }
    $view = $this->get('/admin/sessions/'.$sessionId)->getContent();
    // The player renders the lines from its data with x-text (never as HTML): the data holds the text verbatim.
    $player = TranscriptPlayerEntry::payload(CaptureSession::query()->findOrFail($sessionId));
    expect(array_column($player['segments'], 'text'))->toBe([ADMIN_XSS_SCRIPT, ADMIN_XSS_IMG])
        ->and($player['segments'][0]['speaker'])->toBe('<b>Me</b>');
    expect(adminVisibleText($view))->toContain(ADMIN_XSS_IMG); // the title
    expect($view)->not->toContain('<b>Me</b>')->not->toContain('x-html');
    expect(Device::query()->where('name', ADMIN_XSS_SCRIPT)->exists())->toBeTrue(); // stored verbatim, escaped on output
});

it('does not echo hostile input back in validation messages', function () {
    $login = $this->panelComponent(Login::class)
        ->fillForm(['email' => ADMIN_XSS_SCRIPT, 'password' => ADMIN_XSS_IMG])
        ->call('authenticate');

    foreach (adminRawMarkers() as $marker) {
        expect($login->html())->not->toContain($marker);
    }
});
