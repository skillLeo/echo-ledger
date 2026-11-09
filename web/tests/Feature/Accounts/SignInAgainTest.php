<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PairingService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P3 "Sign in again" (docs/API.md "Signing a device in again"): a one-time code bound to a signed-out, revoked or
| expired row of the person's own; redeemed with POST /api/devices/register, it brings that same row back.
*/

uses(ApiClient::class);

function signedOutPhone(User $owner, array $attributes = []): Device
{
    $device = Fx::device($owner, ['platform' => 'android', 'name' => 'samsung SM-S938B', 'install_id' => 'old-install-0001'] + $attributes);
    $device->forceFill(['name_chosen' => true, 'name' => 'Work phone'])->save();
    $device->signOut('self');

    return $device->fresh();
}

it('brings the same row back: active, its name and recordings kept, a new token, the old ones revoked', function () {
    $ana = Fx::user();
    $device = Fx::device($ana, ['platform' => 'android', 'install_id' => 'old-install-0001']);
    $device->forceFill(['name_chosen' => true, 'name' => 'Work phone'])->save();
    $old = Fx::token($device);
    $session = Fx::session($ana, $device);
    $device->signOut('self');
    $code = app(PairingService::class)->createBoundCode($ana, $device->id);

    $answer = $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'samsung SM-S938B', 'platform' => 'android', 'app_version' => '1.6.0', 'install_id' => 'new-install-0002'])
        ->assertCreated();

    expect($answer->json('device.id'))->toBe($device->id);
    $fresh = Device::query()->withoutGlobalScopes()->findOrFail($device->id);
    expect($fresh->status())->toBe(Device::ACTIVE)->and($fresh->signed_out_at)->toBeNull()->and($fresh->name)->toBe('Work phone')
        ->and($fresh->install_id)->toBe('new-install-0002')->and($fresh->app_version)->toBe('1.6.0')
        ->and(Device::query()->withoutGlobalScopes()->where('user_id', $ana->id)->count())->toBe(1)
        ->and($session->fresh()->device_id)->toBe($device->id);
    $this->api($answer->json('token'))->getJson('/api/config')->assertOk()->assertJsonPath('device.id', $device->id);
    $this->api($old)->getJson('/api/config')->assertUnauthorized();
    // Single use.
    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'x', 'platform' => 'android'])->assertStatus(422);
});

it('works only on the row\'s own platform', function () {
    $device = signedOutPhone($ana = Fx::user());
    $code = app(PairingService::class)->createBoundCode($ana, $device->id);

    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'PC', 'platform' => 'windows'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.pairing_code.0', 'This code signs in Work phone again; use it on that device.');
    expect($device->fresh()->signed_out_at)->not->toBeNull()
        ->and(DevicePairingCode::query()->withoutGlobalScopes()->whereNull('used_at')->count())->toBe(1);
});

it('is refused once the row was removed from the list, and after 10 minutes', function () {
    $ana = Fx::user();
    $device = signedOutPhone($ana);
    $code = app(PairingService::class)->createBoundCode($ana, $device->id);
    $device->delete();
    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'x', 'platform' => 'android'])->assertStatus(422);
    expect(Device::query()->withoutGlobalScopes()->where('user_id', $ana->id)->count())->toBe(0);

    $other = signedOutPhone($ana);
    $late = app(PairingService::class)->createBoundCode($ana, $other->id);
    $this->travel(11)->minutes();
    $this->postJson('/api/devices/register', ['pairing_code' => $late, 'name' => 'x', 'platform' => 'android'])->assertStatus(422);
});

it('counts the row once against the device limit', function () {
    $ana = Fx::user();
    Workspace::query()->whereKey($ana->current_workspace_id)->update(['plan_id' => Plan::free()->id]); // 2 devices
    Fx::device($ana, ['platform' => 'windows']);
    $phone = signedOutPhone($ana);
    $code = app(PairingService::class)->createBoundCode($ana, $phone->id);
    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'x', 'platform' => 'android'])->assertCreated()->assertJsonPath('device.id', $phone->id);

    $third = signedOutPhone($ana, ['install_id' => 'third-install-01']);
    $code = app(PairingService::class)->createBoundCode($ana, $third->id);
    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'x', 'platform' => 'android'])->assertStatus(403);
});

it('never makes a code for another person\'s device, nor for one signed in now', function () {
    $ana = Fx::user();
    $bob = Fx::user();
    $bobs = signedOutPhone($bob);

    expect(fn () => app(PairingService::class)->createBoundCode($ana, $bobs->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(PairingService::class)->createBoundCode($ana, Fx::device($ana)->id))->toThrow(ValidationException::class);
    expect(DevicePairingCode::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->actingAs($ana, 'web');
    Filament::setCurrentPanel('app');
    Livewire::test(EditProfile::class)->mountAction(TestAction::make('signInAgain')->arguments(['device' => $bobs->id]))->assertNotFound();
    expect(DevicePairingCode::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('offers Sign in again on the profile for a row that is signed out, with the code and its QR', function () {
    $ana = Fx::user();
    $device = signedOutPhone($ana);
    Fx::device($ana, ['platform' => 'windows', 'name' => 'Desk PC']);
    $this->actingAs($ana, 'web');

    $this->get('/app/settings/devices')->assertOk()->assertSee('Signed out by you');
    Filament::setCurrentPanel('app');
    $profile = Livewire::test(EditProfile::class)->mountAction(TestAction::make('signInAgain')->arguments(['device' => $device->id]));
    $pairing = $profile->get('mountedActions.0.data.pairing');
    expect($pairing['code'] ?? null)->toMatch('/^[0-9A-Z]{4}-[0-9A-Z]{4}$/')->and($pairing['qr'] ?? '')->not->toBe('')
        ->and(DevicePairingCode::query()->withoutGlobalScopes()->where('bound_device_id', $device->id)->count())->toBe(1);
});
