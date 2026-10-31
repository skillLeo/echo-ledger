<?php

declare(strict_types=1);

use App\Filament\Pages\ApiCredentials;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use App\Policies\CaptureSessionPolicy;
use App\Policies\DevicePolicy;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/** Every console URL, including the ones that need a record. */
function adminConsoleUrls(): array
{
    $owner = User::factory()->create();
    $session = CaptureSession::factory()->create(['user_id' => $owner->id]);
    $client = Client::factory()->create(['user_id' => $owner->id]);

    return [
        '/admin', '/admin/sessions', '/admin/sessions/'.$session->id, '/admin/clients', '/admin/clients/create',
        '/admin/clients/'.$client->id.'/edit', '/admin/devices', '/admin/settings', '/admin/api-credentials', '/admin/profile',
    ];
}

function adminEnrolMfa(User $user): void
{
    $provider = AppAuthentication::make();
    $provider->saveSecret($user, $provider->generateSecret());
}

it('sends a guest from every console URL to the login page', function () {
    foreach (adminConsoleUrls() as $url) {
        $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
    }
});

it('denies a signed-in account that is not a super admin on every console URL', function () {
    $urls = adminConsoleUrls();
    $this->actingAs($this->makeRegularUser(), 'web');

    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('does not let a device bearer token open the console', function () {
    $device = Device::factory()->create(['user_id' => $this->makeAdmin()->id]);
    $token = $device->createToken('device', Device::ABILITIES)->plainTextToken;

    $this->withToken($token)->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->withToken($token)->get('/admin/sessions')->assertRedirect(route('filament.admin.auth.login'));
});

it('does not let a console session call the device API', function () {
    $this->signInAsAdmin();

    $this->getJson('/api/config')->assertUnauthorized();
    $this->getJson('/api/library')->assertUnauthorized();
});

it('hides Settings and API Credentials from anyone who is not a super admin', function () {
    $this->actingAs($this->makeRegularUser(), 'web');
    expect(Settings::canAccess())->toBeFalse()->and(ApiCredentials::canAccess())->toBeFalse();

    auth()->logout();
    $this->actingAs($this->makeAdmin(), 'web');
    expect(Settings::canAccess())->toBeTrue()->and(ApiCredentials::canAccess())->toBeTrue();
});

it('lists the console pages in the navigation for a super admin', function () {
    $this->signInAsAdmin();

    $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel())->all();

    expect($labels)->toContain('Library', 'Clients', 'Activity', 'Upload', 'Devices', 'API Credentials', 'Settings');
});

it('registers only the B20 role policies: recordings and devices; scoping rests on workspace-scoped queries', function () {
    expect(Gate::getPolicyFor(CaptureSession::class))->toBeInstanceOf(CaptureSessionPolicy::class)
        ->and(Gate::getPolicyFor(Device::class))->toBeInstanceOf(DevicePolicy::class)
        ->and(Gate::getPolicyFor(Client::class))->toBeNull();
    expect(collect(glob(app_path('Policies/*.php')))->map(fn (string $f): string => basename($f))->sort()->values()->all())
        ->toBe(['CaptureSessionPolicy.php', 'DevicePolicy.php']);
});

it('scopes every resource query to the signed-in admin', function () {
    $admin = $this->signInAsAdmin();
    $other = $this->makeAdmin();
    $mine = [CaptureSession::factory()->create(['user_id' => $admin->id]), Client::factory()->create(['user_id' => $admin->id]), Device::factory()->create(['user_id' => $admin->id])];
    CaptureSession::factory()->create(['user_id' => $other->id]);
    Client::factory()->create(['user_id' => $other->id]);
    Device::factory()->create(['user_id' => $other->id]);

    expect(CaptureSessionResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine[0]->id])
        ->and(ClientResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine[1]->id])
        ->and(DeviceResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine[2]->id]);
});

it('returns 404, not the record, when one admin asks for another\'s session, client or recording', function () {
    $this->signInAsAdmin();
    $other = $this->makeAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $other->id]);
    $client = Client::factory()->create(['user_id' => $other->id]);

    $this->get('/admin/sessions/'.$session->id)->assertNotFound();
    $this->get('/admin/clients/'.$client->id.'/edit')->assertNotFound();
});

// --- Admin audio route ----------------------------------------------------------------------------------------

it('redirects a guest away from the audio route', function () {
    $session = CaptureSession::factory()->create();
    $recording = $this->readyRecording($session);

    $this->get(route('admin.recordings.stream', $recording))->assertRedirect(route('filament.admin.auth.login'));
});

it('refuses the audio route to a signed-in account that is not a super admin, even with two-factor enrolled', function () {
    $session = CaptureSession::factory()->create();
    $recording = $this->readyRecording($session);
    $regular = $this->makeRegularUser();
    adminEnrolMfa($regular);
    $this->actingAs($regular, 'web');

    $this->get(route('admin.recordings.stream', $recording))->assertForbidden();
});

it('streams decrypted audio to a super admin with two-factor, with private caching and range support', function () {
    $admin = $this->makeAdmin();
    adminEnrolMfa($admin);
    $this->actingAs($admin, 'web');
    $session = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $audio = random_bytes(4096);
    $recording = $this->readyRecording($session, $audio, 'audio/ogg');

    $full = $this->get(route('admin.recordings.stream', $recording))->assertOk();
    expect($full->headers->get('Content-Type'))->toContain('audio/ogg')
        ->and($full->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($full->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($full->streamedContent())->toBe($audio);

    $partial = $this->withHeaders(['Range' => 'bytes=10-19'])->get(route('admin.recordings.stream', $recording));
    $partial->assertStatus(206);
    expect($partial->headers->get('Content-Range'))->toBe('bytes 10-19/4096')
        ->and($partial->streamedContent())->toBe(substr($audio, 10, 10));
});

it('streams audio to a super admin who has not turned on two-factor, because it is optional', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $recording = $this->readyRecording($session, 'RIFF-playable-bytes');

    $this->get(route('admin.recordings.stream', $recording))->assertOk();
});

it('lets the session page play audio for a super admin without two-factor', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'hi']]);
    $recording = $this->readyRecording($session);

    $page = $this->get('/admin/sessions/'.$session->id)->assertOk()->getContent();
    expect($page)->toContain(route('admin.recordings.stream', $recording));

    // The <audio> element requests exactly this URL with the admin's session cookie.
    $this->get(route('admin.recordings.stream', $recording))->assertOk();
});

it('answers 404 for audio that is deleted, not ready, or unknown', function () {
    $admin = $this->makeAdmin();
    adminEnrolMfa($admin);
    $this->actingAs($admin, 'web');
    $deletedSession = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $deleted = $this->readyRecording($deletedSession);
    $notReady = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $pending = $this->readyRecording($notReady);
    $pending->forceFill(['upload_status' => 'uploading', 'stored_path' => null])->save();
    $deletedSession->forceFill(['deleted_at' => now()])->save();

    $this->get(route('admin.recordings.stream', $deleted))->assertNotFound();
    $this->get(route('admin.recordings.stream', $pending))->assertNotFound();
    $this->get('/admin-media/recordings/01JNOSUCHRECORDING0000000')->assertNotFound();
});

it('keeps one super admin from streaming another super admin\'s recording', function () {
    $owner = $this->makeAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $owner->id]);
    $recording = $this->readyRecording($session, 'private-audio-of-the-owner');

    $intruder = $this->makeAdmin();
    adminEnrolMfa($intruder);
    $this->actingAs($intruder, 'web');

    expect($this->get(route('admin.recordings.stream', $recording))->getStatusCode())->toBeIn([403, 404]);
});

// --- Request forgery and framing ----------------------------------------------------------------------------------

/** The framework skips CSRF checks under the test environment; switch that off to exercise the real behaviour. */
function adminEnforceCsrf(): void
{
    app()->instance('env', 'production');
}

it('requires a CSRF token to sign out', function () {
    $this->signInAsAdmin();
    adminEnforceCsrf();

    $this->post(route('filament.admin.auth.logout'))->assertStatus(419);
    $this->assertAuthenticated('web');

    $this->withSession(['_token' => 'known-token'])->post(route('filament.admin.auth.logout'), ['_token' => 'known-token'])->assertRedirect();
});

it('requires a CSRF token or a same-origin request for Livewire updates', function () {
    $this->actingAs($this->makeAdmin(), 'web');
    $html = $this->get('/admin/settings')->assertOk()->getContent();
    preg_match('/<div[^>]*wire:snapshot="([^"]*)"[^>]*wire:name="App\\\\Filament\\\\Pages\\\\Settings"/s', $html, $match);
    $snapshot = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    adminEnforceCsrf();
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]];

    $this->postJson(Livewire\Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertStatus(419);
    expect($this->postJson(Livewire\Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true', 'Sec-Fetch-Site' => 'cross-site'])->getStatusCode())->toBeIn([403, 419]);
    $this->postJson(Livewire\Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true', 'Sec-Fetch-Site' => 'same-origin'])->assertOk();
});

it('forbids framing the console, so its buttons cannot be clickjacked', function () {
    $this->signInAsAdmin();

    $headers = $this->get('/admin')->assertOk()->headers;
    $framing = strtolower((string) $headers->get('X-Frame-Options'));
    $csp = strtolower((string) $headers->get('Content-Security-Policy'));

    expect($framing === 'deny' || $framing === 'sameorigin' || str_contains($csp, "frame-ancestors 'none'") || str_contains($csp, "frame-ancestors 'self'"))->toBeTrue();
});

it('does not let browsers cache the console pages', function () {
    $this->signInAsAdmin();

    $cacheControl = (string) $this->get('/admin/settings')->assertOk()->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')->and(str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'no-cache'))->toBeTrue();
});

// --- Session cookie ---------------------------------------------------------------------------------------------

it('issues the session cookie as HttpOnly with SameSite=Lax', function () {
    $response = $this->get('/admin/login')->assertOk();

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('marks the session cookie Secure when the deployment asks for it', function () {
    config(['session.secure' => true]);

    $response = $this->get('/admin/login')->assertOk();

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
    expect($cookie->isSecure())->toBeTrue();
});
