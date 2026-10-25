<?php

declare(strict_types=1);

use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

const SEC_ADMIN_PAGES = ['/admin', '/admin/sessions', '/admin/clients', '/admin/clients/create', '/admin/devices', '/admin/api-credentials', '/admin/settings'];

/** A super admin who has enrolled an authenticator app. */
function secMfaAdmin(): User
{
    $admin = Fx::superAdmin();
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    return $admin->fresh();
}

it('sends guests to the login page for every console page', function (string $path) {
    $this->get($path)->assertRedirect();
    expect($this->get($path)->headers->get('Location'))->toContain('/admin/login');
})->with(SEC_ADMIN_PAGES);

it('sends guests to the login page for the audio route and never serves bytes', function () {
    Fx::fakeRecordingsDisk();
    $recording = Fx::readyRecording(Fx::session(Fx::user()), 'guest must not hear this');

    $response = $this->get('/admin-media/recordings/'.$recording->id);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/admin/login');
});

it('denies every console page to a signed-in user who is not a super admin', function (string $path) {
    $this->actingAs(Fx::user());

    $this->get($path)->assertForbidden();
})->with(SEC_ADMIN_PAGES);

it('lets a super admin open every console page', function (string $path) {
    $this->actingAs(Fx::superAdmin());

    $this->get($path)->assertOk();
})->with(SEC_ADMIN_PAGES);

it('denies the audio route to a signed-in user who is not a super admin', function () {
    Fx::fakeRecordingsDisk();
    $user = Fx::user();
    $recording = Fx::readyRecording(Fx::session($user), 'not for regular users');
    $this->actingAs($user);

    $this->get('/admin-media/recordings/'.$recording->id)->assertForbidden();
});

it('plays a recording for a signed-in super admin while the panel does not require two-factor sign-in', function () {
    Fx::fakeRecordingsDisk();
    $admin = Fx::superAdmin();
    $recording = Fx::readyRecording(Fx::session($admin), 'owner audio bytes');
    $this->actingAs($admin);
    $panelRequiresMfa = Filament::getPanel('admin')->isMultiFactorAuthenticationRequired();

    $response = $this->get('/admin-media/recordings/'.$recording->id);

    expect($panelRequiresMfa)->toBeFalse();
    $response->assertOk();
})->skip('A6.W1: the console audio route returns 403 to admins without two-factor, although two-factor is optional');

it('streams decrypted audio to an enrolled super admin with safe headers and range support', function () {
    Fx::fakeRecordingsDisk();
    $admin = secMfaAdmin();
    $bytes = random_bytes(2048);
    $recording = Fx::readyRecording(Fx::session($admin), $bytes);
    $this->actingAs($admin);

    $full = $this->get('/admin-media/recordings/'.$recording->id)->assertOk();
    ob_start();
    $full->baseResponse->sendContent();
    expect(ob_get_clean())->toBe($bytes);
    expect($full->headers->get('Cache-Control'))->toContain('no-store')->and($full->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $this->withHeaders(['Range' => 'bytes=0-9'])->get('/admin-media/recordings/'.$recording->id)->assertStatus(206);
});

it('does not play one admin\'s recording to another admin', function () {
    Fx::fakeRecordingsDisk();
    $owner = secMfaAdmin();
    $other = secMfaAdmin();
    $recording = Fx::readyRecording(Fx::session($owner, null, ['title' => 'Owner private call']), 'owner only audio');
    $this->actingAs($other);

    $status = $this->get('/admin-media/recordings/'.$recording->id)->getStatusCode();

    expect($status)->toBeIn([403, 404]);
})->skip('A6.W2: the console audio route is not scoped to the recording owner');

it('answers 404 for audio that is missing, deleted or not ready', function () {
    Fx::fakeRecordingsDisk();
    $admin = secMfaAdmin();
    $this->actingAs($admin);
    $pending = Fx::pendingRecording(Fx::session($admin), str_repeat('p', 2048), 1024);
    $deleted = Fx::session($admin);
    $gone = Fx::readyRecording($deleted, 'soft deleted');
    DB::table('capture_sessions')->where('id', $deleted->id)->update(['deleted_at' => now()]);

    $this->get('/admin-media/recordings/'.$pending->id)->assertNotFound();
    $this->get('/admin-media/recordings/'.$gone->id)->assertNotFound();
    $this->get('/admin-media/recordings/'.Fx::MISSING_ID)->assertNotFound();
    $this->get('/admin-media/recordings/..%2f..%2f.env')->assertNotFound();
});

it('does not let a device token open the console or its media route', function () {
    $token = Fx::token(Fx::device());

    $this->api($token, false)->get('/admin/sessions')->assertRedirect();
    $this->api($token, false)->get('/admin-media/recordings/'.Fx::MISSING_ID)->assertRedirect();
});

it('locks the login form after five attempts in a minute even when the sixth password is right', function () {
    $admin = Fx::superAdmin(['password' => 'a-long-correct-password']);
    Filament::setCurrentPanel('admin');
    RateLimiter::clear('filament');
    Cache::flush();

    $page = Livewire::test(Login::class);
    for ($i = 0; $i < 5; $i++) {
        $page->fillForm(['email' => $admin->email, 'password' => 'wrong-password-'.$i])->call('authenticate');
    }
    $page->fillForm(['email' => $admin->email, 'password' => 'a-long-correct-password'])->call('authenticate');

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('accepts the correct password when it is the first attempt', function () {
    $admin = Fx::superAdmin(['password' => 'a-long-correct-password']);
    Filament::setCurrentPanel('admin');
    Cache::flush();

    Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'a-long-correct-password'])->call('authenticate');

    expect(Auth::guard('web')->check())->toBeTrue();
});

it('limits wrong two-factor codes so a six-digit code cannot be brute-forced', function () {
    $admin = secMfaAdmin();
    $admin->forceFill(['password' => 'a-long-correct-password'])->save();
    Filament::setCurrentPanel('admin');
    Cache::flush();
    $google = app(Google2FA::class);

    $page = Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'a-long-correct-password'])->call('authenticate');
    for ($i = 0; $i < 5; $i++) {
        $page->set('data.multiFactor.app.code', '00000'.$i)->call('authenticate');
    }
    $page->set('data.multiFactor.app.code', $google->getCurrentOtp('JBSWY3DPEHPK3PXP'))->call('authenticate');

    expect(Auth::guard('web')->check())->toBeFalse('a correct code was accepted after five wrong ones in the same minute');
});

it('accepts the correct two-factor code on a first attempt', function () {
    $admin = secMfaAdmin();
    $admin->forceFill(['password' => 'a-long-correct-password'])->save();
    Filament::setCurrentPanel('admin');
    Cache::flush();

    $page = Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'a-long-correct-password'])->call('authenticate');
    $page->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP'))->call('authenticate');

    expect(Auth::guard('web')->check())->toBeTrue();
});
it('gives the same answer for an unknown account and a wrong password', function () {
    $admin = Fx::superAdmin(['password' => 'a-long-correct-password']);
    Filament::setCurrentPanel('admin');
    Cache::flush();

    $unknown = Livewire::test(Login::class)->fillForm(['email' => 'nobody@example.test', 'password' => 'whatever-123'])->call('authenticate');
    $unknownMessage = $unknown->errors()->first('data.email');
    Cache::flush();
    $wrong = Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'wrong-password'])->call('authenticate');

    expect($unknownMessage)->not->toBe('')->and($wrong->errors()->first('data.email'))->toBe($unknownMessage);
});

it('does not sign a regular user into the console even with the right password', function () {
    $user = Fx::user(['password' => 'a-long-correct-password']);
    Filament::setCurrentPanel('admin');
    Cache::flush();

    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'a-long-correct-password'])->call('authenticate');

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('keeps each admin to their own sessions, clients and devices in the console', function () {
    $alice = Fx::superAdmin();
    $bob = Fx::superAdmin();
    $aliceSession = Fx::session($alice, null, ['title' => 'Alice call']);
    $bobSession = Fx::session($bob, null, ['title' => 'Bob call']);
    $this->actingAs($alice);
    Filament::setCurrentPanel('admin');

    Livewire::test(ListCaptureSessions::class)->assertCanSeeTableRecords([$aliceSession])->assertCanNotSeeTableRecords([$bobSession]);

    $this->get('/admin/sessions/'.$bobSession->id)->assertNotFound();
    $this->get('/admin/sessions/'.$aliceSession->id)->assertOk();
});

it('cannot delete another admin\'s data through the console actions', function () {
    $alice = Fx::superAdmin();
    $bob = Fx::superAdmin();
    $bobSession = Fx::session($bob, null, ['title' => 'Bob call']);
    $bobClient = Client::query()->create(['user_id' => $bob->id, 'name' => 'Bob client']);
    $bobDevice = Fx::device($bob);
    $this->actingAs($alice);
    Filament::setCurrentPanel('admin');

    foreach ([
        fn () => Livewire::test(ListCaptureSessions::class)->callTableAction('delete', $bobSession->id),
        fn () => Livewire::test(ListDevices::class)->callTableAction('delete', $bobDevice->id),
        fn () => Livewire::test(ListDevices::class)->callTableAction('rename', $bobDevice->id, ['name' => 'hijacked']),
        fn () => Livewire::test(ListClients::class)->callTableAction('delete', $bobClient->id),
    ] as $attempt) {
        try {
            $attempt();
        } catch (Throwable) {
            // refusing loudly is fine; what matters is that nothing changed
        }
    }

    expect(CaptureSession::query()->find($bobSession->id))->not->toBeNull()
        ->and(Client::query()->find($bobClient->id))->not->toBeNull()
        ->and(Device::query()->find($bobDevice->id)?->name)->toBe($bobDevice->name);
});

it('treats table search and sort input as data', function (string $payload) {
    $admin = Fx::superAdmin();
    Fx::session($admin, null, ['title' => 'Quarterly review']);
    Fx::session($admin, null, ['title' => '100% done_deal']);
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    $page = Livewire::test(ListCaptureSessions::class)->searchTable($payload)->assertSuccessful();
    $page->sortTable($payload)->assertSuccessful();

    expect(User::query()->count())->toBe(1)->and(CaptureSession::query()->count())->toBe(2);
})->with([
    'quote or true' => ["' OR '1'='1"],
    'stacked drop' => ["'; DROP TABLE capture_sessions; --"],
    'union select' => ["' UNION SELECT password FROM users --"],
    'percent' => ['%'],
    'underscore' => ['_'],
    'backslash' => ['\\'],
    'escaped percent' => ['\\%'],
    'null byte' => ["a\0b"],
    'huge' => [str_repeat('A', 5000)],
    'unicode' => ["\u{202E}\u{1F4A5}"],
    'column injection' => ['title; DROP TABLE users'],
]);

/**
 * Every outbound provider URL field of the settings page paired with each of the given addresses.
 *
 * @param  list<string>  $urls
 * @return array<string, array{0: string, 1: string}>
 */
function secProviderUrlCases(array $urls): array
{
    $cases = [];
    foreach (['elevenlabs_base_url', 'azure_endpoint', 'whisper_base_url'] as $field) {
        foreach ($urls as $url) {
            $cases["{$field} <- {$url}"] = [$field, $url];
        }
    }

    return $cases;
}

it('rejects non-https and non-web schemes for every outbound provider URL in the settings', function (string $field, string $url) {
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(Settings::class)->fillForm([$field => $url])->call('save')->assertHasFormErrors([$field]);
})->with(secProviderUrlCases(['file:///etc/passwd', 'javascript:alert(1)']));

it('rejects plain http, ftp and gopher for every outbound provider URL in the settings', function (string $field, string $url) {
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(Settings::class)->fillForm([$field => $url])->call('save')->assertHasFormErrors([$field]);
})->with(secProviderUrlCases(['gopher://127.0.0.1:6379/_PING', 'ftp://example.com/x', 'http://example.com/plain-text-key']))
    ->skip('A6.W10: provider endpoint URLs accept non-HTTPS schemes');

it('accepts https provider URLs in the settings', function (string $field) {
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(Settings::class)->fillForm([$field => 'https://example.com/path'])->call('save')->assertHasNoFormErrors();
})->with(['elevenlabs_base_url', 'azure_endpoint', 'whisper_base_url']);

it('refuses settings that would break the service', function () {
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    foreach ([['retention_days', 0], ['retention_days', 366], ['retention_days', -5], ['retention_days', 'abc'], ['whisper_chunk_seconds', 59], ['whisper_chunk_seconds', 1801], ['display_timezone', 'Mars/Olympus']] as [$field, $value]) {
        Livewire::test(Settings::class)->fillForm([$field => $value])->call('save')->assertHasFormErrors([$field]);
    }
});

it('rejects an Azure region that is not a plain region name, because it is placed inside a hostname', function (string $region) {
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(Settings::class)->fillForm(['azure_region' => $region])->call('save')->assertHasFormErrors(['azure_region']);
})->with(['attacker.example/x?', 'x@attacker.example#', 'a b', '../x', 'east.us', "a\nb", 'EASTUS!', str_repeat('a', 80)])
    ->skip('A6.W10: the Azure region is not validated although it becomes part of the provider host name');

it('accepts ordinary Azure region names', function (string $region) {
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(Settings::class)->fillForm(['azure_region' => $region])->call('save')->assertHasNoFormErrors();
})->with(['centralindia', 'eastus', 'westeurope', 'uksouth']);
