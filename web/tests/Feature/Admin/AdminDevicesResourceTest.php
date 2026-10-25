<?php

declare(strict_types=1);

use App\Enums\SourceDevice;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use chillerlan\QRCode\QRCode;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/** Opens the "Pair a device" modal and returns [component, displayed code, modal html]. */
function adminOpenPairing($test): array
{
    $list = $test->panelComponent(ListDevices::class)->mountAction('pair');
    $html = $test->modalHtml($list);
    preg_match('/\b([0-9A-Z]{4}-[0-9A-Z]{4})\b/', strip_tags($html), $match);

    return [$list, $match[1] ?? '', $html];
}

it('renders the devices list and has no create or edit pages', function () {
    $this->signInAsAdmin();

    $this->get('/admin/devices')->assertOk()->assertSee('Devices')->assertSee('Pair a device');
    $this->get('/admin/devices/create')->assertNotFound();
});

it('shows an empty state with a hint about pairing', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ListDevices::class)
        ->assertSee('No devices paired')
        ->assertSee('Use "Pair a device" to connect the EchoLedger phone or desktop app.');
});

it('lists each device with its type, platform, version, session count and last seen time', function () {
    $admin = $this->signInAsAdmin();
    $phone = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Pixel 9', 'app_version' => '1.4.2', 'last_seen_at' => now()->subMinutes(5)]);
    $desktop = Device::factory()->desktop()->create(['user_id' => $admin->id, 'name' => 'Work laptop', 'app_version' => null, 'last_seen_at' => null]);
    CaptureSession::factory()->count(3)->create(['user_id' => $admin->id, 'device_id' => $phone->id]);

    $list = $this->panelComponent(ListDevices::class)
        ->assertCanSeeTableRecords([$phone, $desktop])
        ->assertSee(['Pixel 9', 'Work laptop', 'Phone', 'Desktop', 'Android', '1.4.2', '5 minutes ago', 'never']);

    $counts = $list->instance()->getTableRecords()->pluck('capture_sessions_count', 'name');
    expect($counts['Pixel 9'])->toBe(3)->and($counts['Work laptop'])->toBe(0);
});

it('only lists the signed-in admin\'s devices', function () {
    $admin = $this->signInAsAdmin();
    $mine = Device::factory()->create(['user_id' => $admin->id]);
    $theirs = Device::factory()->create(['user_id' => $this->makeAdmin()->id]);

    $this->panelComponent(ListDevices::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('orders devices by most recently seen and searches by name', function () {
    $admin = $this->signInAsAdmin();
    $old = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Old tablet', 'last_seen_at' => now()->subDays(9)]);
    $new = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Fresh phone', 'last_seen_at' => now()->subMinute()]);

    $list = $this->panelComponent(ListDevices::class);
    $list->assertCanSeeTableRecords([$new, $old], inOrder: true);
    $list->searchTable('tablet')->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$new]);
});

it('renames a device', function () {
    $admin = $this->signInAsAdmin();
    $device = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Pixel 9']);

    $this->panelComponent(ListDevices::class)
        ->mountAction(TestAction::make('rename')->table($device))
        ->assertSchemaStateSet(['name' => 'Pixel 9'])
        ->fillForm(['name' => 'Farah\'s Pixel'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($device->fresh()->name)->toBe('Farah\'s Pixel');
});

it('rejects a blank or over-long device name', function () {
    $admin = $this->signInAsAdmin();
    $device = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Pixel 9']);

    $list = $this->panelComponent(ListDevices::class)->mountAction(TestAction::make('rename')->table($device));
    $list->fillForm(['name' => ''])->callMountedAction()->assertHasActionErrors(['name' => 'required']);
    $list->fillForm(['name' => '   '])->callMountedAction()->assertHasActionErrors(['name']);
    $list->fillForm(['name' => str_repeat('d', 121)])->callMountedAction()->assertHasActionErrors(['name' => 'max']);

    expect($device->fresh()->name)->toBe('Pixel 9');
});

it('revokes a device, deleting it and every one of its tokens', function () {
    $admin = $this->signInAsAdmin();
    $device = Device::factory()->create(['user_id' => $admin->id]);
    $device->createToken('device', Device::ABILITIES);
    $device->createToken('device', Device::ABILITIES);
    expect(DB::table('personal_access_tokens')->count())->toBe(2);

    $this->panelComponent(ListDevices::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($device));

    expect(Device::query()->find($device->id))->toBeNull()
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('locks a revoked device out of the API straight away', function () {
    $admin = $this->signInAsAdmin();
    $device = Device::factory()->create(['user_id' => $admin->id]);
    $token = $device->createToken('device', Device::ABILITIES)->plainTextToken;

    $this->withToken($token)->getJson('/api/config')->assertOk();

    // The API call switched the default guard to the device; sign the admin back in for the console.
    $this->app['auth']->forgetGuards();
    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');
    $this->panelComponent(ListDevices::class)->callAction(TestAction::make(DeleteAction::class)->table($device));
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/config')->assertUnauthorized();
});

it('labels the revoke action and warns that past recordings stay', function () {
    $admin = $this->signInAsAdmin();
    $device = Device::factory()->create(['user_id' => $admin->id]);

    $list = $this->panelComponent(ListDevices::class)->mountAction(TestAction::make(DeleteAction::class)->table($device));
    $modal = strip_tags($this->modalHtml($list));

    expect($modal)->toContain('Revoke device')->toContain('Its past recordings stay in the library.');
});

it('keeps a revoked device\'s sessions in the library without calling them web uploads', function () {
    $admin = $this->signInAsAdmin();
    $device = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Pixel 9']);
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'device_id' => $device->id, 'source_device' => SourceDevice::Phone, 'title' => 'Call from phone']);

    $this->panelComponent(ListDevices::class)->callAction(TestAction::make(DeleteAction::class)->table($device));

    expect($session->fresh())->not->toBeNull();
    $this->panelComponent(ListCaptureSessions::class)
        ->assertCanSeeTableRecords([$session])
        ->assertSee('Call from phone')
        ->assertDontSee('Web upload');
});

it('cannot revoke or rename another admin\'s device', function () {
    $this->signInAsAdmin();
    $theirs = Device::factory()->create(['user_id' => $this->makeAdmin()->id]);

    $this->panelComponent(ListDevices::class)->assertCanNotSeeTableRecords([$theirs]);
    expect(unscoped(fn () => Device::query()->find($theirs->id)))->not->toBeNull();
});

// --- Pair a device --------------------------------------------------------------------------------------------

it('creates a one-time pairing code in XXXX-XXXX form when the dialog opens', function () {
    $admin = $this->signInAsAdmin();

    [, $code, $html] = adminOpenPairing($this);

    expect($code)->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/');
    expect(DevicePairingCode::query()->count())->toBe(1);
    expect(DevicePairingCode::query()->first())
        ->user_id->toBe($admin->id)
        ->used_at->toBeNull();
    expect($html)->toContain('Pairing QR code')
        ->toContain('The code is stored only as a hash and cannot be shown again.');
});

it('stores only a hash of the code and expires it after ten minutes', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00:00'));
    $this->signInAsAdmin();

    [, $code] = adminOpenPairing($this);

    $row = DevicePairingCode::query()->firstOrFail();
    $raw = str_replace('-', '', $code);
    expect($row->code_hash)->toBe(hash('sha256', $raw))
        ->and($row->expires_at->toDateTimeString())->toBe('2026-10-24 09:10:00');
    foreach (DB::table('device_pairing_codes')->get() as $record) {
        expect(json_encode($record))->not->toContain($raw)->not->toContain($code);
    }
    Carbon::setTestNow();
});

it('creates exactly one code per dialog and a fresh one each time', function () {
    $this->signInAsAdmin();

    [$list, $first] = adminOpenPairing($this);
    expect(DevicePairingCode::query()->count())->toBe(1);

    $list->call('unmountAction');
    [, $second] = adminOpenPairing($this);

    expect($second)->not->toBe($first)
        ->and(DevicePairingCode::query()->count())->toBe(2);
});

it('encodes the server address, normalised code and pins in the QR code', function () {
    $this->signInAsAdmin();
    $pins = ['sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'sha256/BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB='];
    app(SettingsRepository::class)->set('app.pins', $pins);

    [, $code, $html] = adminOpenPairing($this);

    $expected = json_encode(['v' => 1, 'url' => rtrim((string) config('app.url'), '/'), 'code' => str_replace('-', '', $code), 'pins' => $pins], JSON_UNESCAPED_SLASHES);
    $expectedQr = (new QRCode)->render($expected);
    expect(html_entity_decode($html))->toContain($expectedQr);
    expect(PairingService::normalize($code))->toBe(str_replace('-', '', $code));
});

it('shows the server address next to the code', function () {
    $this->signInAsAdmin();

    [, , $html] = adminOpenPairing($this);

    expect(strip_tags($html))->toContain('Server: '.rtrim((string) config('app.url'), '/'));
});

it('does not keep the code in the page once the dialog is closed', function () {
    $this->signInAsAdmin();
    [$list, $code] = adminOpenPairing($this);
    expect(json_encode($list->snapshot))->toContain($code);

    $list->call('unmountAction');

    expect(json_encode($list->snapshot))->not->toContain($code)->not->toContain(str_replace('-', '', $code));
    expect($list->html())->not->toContain($code);
});

it('has no submit button, only a Done button', function () {
    $this->signInAsAdmin();

    [, , $html] = adminOpenPairing($this);

    expect($html)->toContain('Done')->not->toContain('type="submit"');
});

it('lets the displayed code register a device once, and only once', function () {
    $admin = $this->signInAsAdmin();
    [, $code] = adminOpenPairing($this);

    $payload = ['pairing_code' => $code, 'name' => 'Pixel 9', 'platform' => 'android', 'app_version' => '1.0.0', 'os_version' => '16'];
    $first = $this->postJson('/api/devices/register', $payload)->assertCreated();
    expect($first->json('token'))->toMatch('/^\d+\|eld_/');
    expect(Device::query()->where('user_id', $admin->id)->where('name', 'Pixel 9')->exists())->toBeTrue();

    $this->postJson('/api/devices/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('pairing_code');
});

it('accepts the displayed code typed in lower case without the hyphen', function () {
    $this->signInAsAdmin();
    [, $code] = adminOpenPairing($this);

    $this->postJson('/api/devices/register', [
        'pairing_code' => strtolower(str_replace('-', '', $code)), 'name' => 'Laptop', 'platform' => 'windows',
    ])->assertCreated();
});

it('refuses the code once ten minutes have passed', function () {
    $this->signInAsAdmin();
    [, $code] = adminOpenPairing($this);

    $this->travel(11)->minutes();

    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Late', 'platform' => 'android'])
        ->assertUnprocessable();
});

it('shows the newly paired device in the list', function () {
    $this->signInAsAdmin();
    [, $code] = adminOpenPairing($this);
    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Fresh Pixel', 'platform' => 'android'])->assertCreated();

    $this->panelComponent(ListDevices::class)->assertSee('Fresh Pixel')->assertSee('Phone');
});
