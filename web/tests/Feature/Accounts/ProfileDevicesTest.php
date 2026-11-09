<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\KnownBrowser;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\DeviceSignIn;
use App\Services\PairingService;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
});

/** A console session row as the database session driver keeps it. */
function plantBrowser(User $user, string $id, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36'): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.5', 'user_agent' => $agent, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);
}

it('lists the person\'s own devices in every workspace, and nobody else\'s', function () {
    $member = Fx::user(['name' => 'Member']);
    $owner = Fx::user();
    $team = Workspace::query()->findOrFail($owner->current_workspace_id);
    app(Workspaces::class)->addMember($team, $member);
    Device::factory()->create(['user_id' => $member->id, 'name' => 'Member phone', 'platform' => 'android', 'last_seen_at' => now()->subMinutes(5)]);
    unscoped(fn () => Device::factory()->create(['user_id' => $member->id, 'workspace_id' => $team->id, 'name' => 'Team laptop', 'platform' => 'windows']));
    Device::factory()->create(['user_id' => $owner->id, 'name' => 'Owner secret tablet']);
    $this->actingAs($member->fresh(), 'web');

    $this->get('/app/settings/devices')->assertOk()->assertSee('Devices')->assertSee('Member phone')->assertSee('Team laptop')
        ->assertSee('Sign in a device')->assertDontSee('Owner secret tablet');
    $this->get('/app/settings/security')->assertOk()->assertSee('Sign out everywhere');
});

it('makes a pairing code for the person\'s own account, not only for admins', function () {
    $member = Fx::user();
    expect($member->fresh()->is_super_admin)->toBeFalse();
    $this->actingAs($member, 'web');

    Livewire::test(EditProfile::class, ['subpage' => 'devices'])->mountAction('signInDevice')
        ->assertHasNoErrors();

    $pairing = unscoped(fn () => DevicePairingCode::query()->sole());
    expect($pairing->user_id)->toBe($member->id)->and($pairing->getAttribute('workspace_id'))->toBe($member->current_workspace_id);
});

it('revokes one of the person\'s devices but never someone else\'s', function () {
    $member = Fx::user();
    $other = Fx::user();
    $mine = Device::factory()->create(['user_id' => $member->id]);
    $theirs = Device::factory()->create(['user_id' => $other->id]);
    $this->actingAs($member, 'web');

    Livewire::test(EditProfile::class)->call('revokeDevice', $theirs->id)->assertNotified('That device was already signed out')
        ->call('revokeDevice', $mine->id)->assertNotified('Device signed out');

    // Since v1.6 the person's row stays, signed out; Remove from list takes it away. Someone else's is never touched.
    expect(unscoped(fn () => $mine->fresh()->status()))->toBe(Device::SIGNED_OUT)->and(unscoped(fn () => $theirs->fresh()->status()))->toBe(Device::ACTIVE);
    Livewire::test(EditProfile::class)->call('removeDevice', $theirs->id)->assertNotified('That device was already removed')
        ->call('removeDevice', $mine->id)->assertNotified('Removed from the list');
    expect(unscoped(fn () => Device::query()->pluck('id')->all()))->toBe([$theirs->id]);
});

it('signs out everywhere from the profile: every device, token and browser, this one included', function () {
    config(['session.driver' => 'database']);
    $member = Fx::user();
    $member->forceFill(['remember_token' => 'old-remember-token'])->save();
    [, $token] = $this->enroll($member);
    Device::factory()->create(['user_id' => $member->id]);
    plantBrowser($member, str_repeat('a', 40));
    plantBrowser(Fx::user(), str_repeat('b', 40));
    $this->actingAs($member, 'web');

    Livewire::test(EditProfile::class, ['subpage' => 'security'])->callAction('signOutEverywhere')
        ->assertRedirect(Filament::getPanel('app')->getLoginUrl());

    // Since v1.6 the rows stay, signed out, with no token left.
    expect(unscoped(fn () => Device::query()->where('user_id', $member->id)->whereNull('signed_out_at')->count()))->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_type', Device::class)->whereIn('tokenable_id', unscoped(fn () => Device::query()->where('user_id', $member->id)->pluck('id')))->count())->toBe(0)
        ->and(PersonalAccessToken::findToken($token))->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(1)
        ->and($member->fresh()->remember_token)->not->toBe('old-remember-token')
        ->and(AuditEvent::query()->where('event', 'signed_out_everywhere')->where('user_id', $member->id)->exists())->toBeTrue();
    $this->assertGuest('web');
});

it('drops the pairing and browser sign-in codes not used yet when signing out everywhere', function () {
    $member = Fx::user();
    $this->actingAs($member, 'web');
    $display = app(PairingService::class)->createCode($member);
    $verifier = str_repeat('v', 64);
    $code = app(DeviceSignIn::class)->approve($member, ['code_challenge' => DeviceSignIn::challengeOf($verifier), 'name' => 'Pixel', 'platform' => 'android']);
    $other = Fx::user();
    $kept = app(PairingService::class)->createCode($other);

    app(AccountSessions::class)->signOutEverywhere($member);

    app('auth')->forgetGuards();
    $device = ['name' => 'Pixel', 'platform' => 'android', 'app_version' => '1.5.0', 'os_version' => '16'];
    $this->postJson('/api/devices/register', ['pairing_code' => $display, ...$device])->assertStatus(422);
    $this->postJson('/api/auth/device/token', ['code' => $code, 'code_verifier' => $verifier, ...$device])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    $this->postJson('/api/devices/register', ['pairing_code' => $kept, ...$device])->assertCreated();
});

it('pairs no device for an account in its deletion grace', function () {
    $member = Fx::user();
    $display = app(PairingService::class)->createCode($member);
    $member->forceFill(['deletion_requested_at' => now(), 'deletes_at' => now()->addDays(7)])->save();

    $this->postJson('/api/devices/register', ['pairing_code' => $display, 'name' => 'Pixel', 'platform' => 'android', 'app_version' => '1.5.0', 'os_version' => '16'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    // Since v1.6 the rows stay, signed out, with no token left.
    expect(unscoped(fn () => Device::query()->where('user_id', $member->id)->whereNull('signed_out_at')->count()))->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_type', Device::class)->whereIn('tokenable_id', unscoped(fn () => Device::query()->where('user_id', $member->id)->pluck('id')))->count())->toBe(0);
});

it('signs out everywhere from the API, the asking device included (204)', function () {
    config(['session.driver' => 'database']);
    $member = Fx::user();
    [$device, $token] = $this->enroll($member);
    Device::factory()->create(['user_id' => $member->id]);
    plantBrowser($member, str_repeat('c', 40));
    $bystander = Fx::user();
    Device::factory()->create(['user_id' => $bystander->id]);

    $this->asToken($token)->postJson('/api/account/sign-out-everywhere')->assertNoContent();

    // Since v1.6 the rows stay, signed out, with no token left.
    expect(unscoped(fn () => Device::query()->where('user_id', $member->id)->whereNull('signed_out_at')->count()))->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_type', Device::class)->whereIn('tokenable_id', unscoped(fn () => Device::query()->where('user_id', $member->id)->pluck('id')))->count())->toBe(0)
        ->and(unscoped(fn () => Device::query()->where('user_id', $bystander->id)->count()))->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->asToken($token)->getJson('/api/config')->assertUnauthorized();
});

it('shows a console browser\'s country from the account\'s last sign-in at that address', function () {
    config(['session.driver' => 'database']);
    $member = Fx::user();
    plantBrowser($member, str_repeat('d', 40));
    KnownBrowser::query()->create(['user_id' => $member->id, 'token_hash' => str_repeat('e', 64), 'label' => 'Chrome on Windows', 'ip' => '203.0.113.5', 'country' => 'AE', 'last_used_at' => now()]);
    // Another account's sign-in from the same address says nothing about this one.
    KnownBrowser::query()->create(['user_id' => Fx::user()->id, 'token_hash' => str_repeat('f', 64), 'label' => 'Firefox', 'ip' => '203.0.113.5', 'country' => 'PK', 'last_used_at' => now()->addMinute()]);
    $this->actingAs($member, 'web');

    $this->get('/app/settings/security')->assertOk()->assertSeeText('AE')->assertDontSeeText('PK');
});

it('shows the device\'s country when the server\'s proxy names it', function () {
    config(['echoledger.country_header' => 'CF-IPCountry']);
    $member = Fx::user();
    [$device, $token] = $this->enroll($member);
    // The last-seen time (and with it the address and country) is written at most once a minute.
    $this->travel(2)->minutes();
    $this->asToken($token)->withHeaders(['CF-IPCountry' => 'pk'])->getJson('/api/config')->assertOk();
    expect(unscoped(fn () => $device->fresh()->last_country))->toBe('PK');

    config(['echoledger.country_header' => null]);
    $this->travel(2)->minutes();
    $this->asToken($token)->withHeaders(['CF-IPCountry' => 'US'])->getJson('/api/config')->assertOk();
    expect(unscoped(fn () => $device->fresh()->last_country))->toBeNull();

    $this->actingAs($member, 'web');
    $this->get('/app/settings/devices')->assertOk()->assertSee($device->readableName());
});
